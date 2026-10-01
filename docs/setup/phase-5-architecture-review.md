# Phase 5 Architecture Review

## Status

**PHASE 5 STATUS: READY WITH CONDITIONS** for the approved PC-first server/API architecture. This supersedes the earlier implementation-blocked wording below: cPanel is the future Laravel deployment target, not the current development/runtime environment. Production rollout still requires verification of the actual cPanel account limits because they determine whether bounded uploads, database queue execution, cron scheduling, artifact retention and lease timing are safe. Do not guess those limits or enable production sources until the preflight below is completed.

## Authoritative documents read

Read all of the following phase documents and compared their ownership/dependency rules:

- `docs/architecture/phase-0-foundation.md`
- `docs/architecture/phase-0.1-architecture-patch.md`
- `docs/architecture/phase-2-scholarship-core.md`
- `docs/architecture/phase-2.2-verification-publication.md`
- `docs/architecture/phase-3-search-discovery.md`
- `docs/architecture/phase-4-data-quality-review.md`
- `docs/setup/phase-4-architecture-review.md`
- `docs/setup/phase-4-verification.md`

No conflict was found in lifecycle ownership: Phase 5 produces immutable observations and hands them to Phase 4; Phase 4 owns processing/review/provenance; Phase 2.2 owns verification/publication; Phase 3 remains a published-snapshot read path. Phase 0.1's source registry and worker-only API boundary are the authority over Phase 0's earlier logical ERD wording. No existing phase document was edited.

## Current implementation inspected

Inspected source registry/model and migration; Phase 4 observation model, migration, ingestion/processing/normalization/validation/change/review services, Form Requests and routes/controller; users/roles/Gates and API envelope/error handling; queue/filesystem/auth/logging config; console scheduler file; core schema and Phase 2.2/3 integration. Key evidence:

| Area | Current fact | Phase 5 consequence |
|---|---|---|
| Source registry | `scholarship_sources` has optional scholarship/university owner, type/name, canonical URL/hash, primary, active status, notes. | Extend this registry; do not create a second source catalog. Crawl method/frequency/scope/robots configuration is not implemented. |
| Phase 4 intake | `POST /api/v1/admin/data-quality/observations` is under Sanctum + admin permission and accepts a `raw_payload` string capped at 4 MiB. | Worker gets a separate `/api/v1/crawler/...` API and principal. Do not grant admin permissions or call the admin route. |
| Phase 4 artifact | `ObservationIngestionService` hashes payload and stores under private `local` disk at `storage/app/private`; observation receipt is `(source_id,idempotency_key)`. | Upload must be staged/verified server-side and call a backward-compatible Phase 4 adapter; no worker-supplied storage path. |
| Phase 4 processor | `ObservationProcessor` reads the raw artifact and `JsonCandidateExtractor` parses JSON synchronously; no HTML/PDF parser registry/result input currently exists. | Phase 5 needs an explicit, backward-compatible parser-result adapter that preserves raw observation and keeps normalization/validation/detection in Phase 4. Preserve the existing JSON path and run history. |
| Source URL validation | Current observation service checks host equality or any subdomain suffix; it does not enforce path scope, redirect scope, DNS/IP safety, or SSRF controls. | Worker API must independently enforce strict configured host/path scope and DNS/redirect protections before handoff. |
| Authentication | Browser API uses Sanctum stateful sessions; `User` has no `HasApiTokens`, `auth.php` only declares the web guard, and admin APIs rely on permission Gates. | Use dedicated worker identities and hashed/revocable worker tokens with separate middleware/scopes. |
| API | `ApiResponse` envelope and validation/401/403 envelopes are configured; application API prefix is `/api/v1`. | Crawler API follows the existing envelope and prefix. |
| Queue | `config/queue.php` defaults to database queue and its `jobs` table exists; `after_commit` currently defaults false. | Phase 5 must use a same-DB transactional queue/outbox handoff and cPanel-bounded worker invocations. |
| Scheduler | `routes/console.php` contains only the example `inspire` command; no schedule is defined. | Add due-job scheduling and lease reaping via Laravel Scheduler; scheduler never fetches. |
| Audit | JSON Laravel logging exists; there is no crawler job/attempt/event audit table or generic `audit_logs` table. | Add append-only crawler job/credential/admin events; do not rely on logs as audit history. |
| Storage | Private `local` disk exists (root `storage/app/private`); an S3 driver is configured but no provider is selected. | Start with private local artifacts; object storage remains a later measured deployment choice. |
| Runtime limits | Composer requires PHP `^8.3`, Laravel `^12`, Sanctum `^4.3`; this is a local checkout, not the actual cPanel account. | Production PHP/MySQL/cron/upload/memory/storage/backup limits remain unverified. |

## Server ↔ PC architecture

Laravel/cPanel owns source configuration, due/manual job creation, workers/tokens, atomic claims, leases, source/global rate admission, retries, accepted artifact storage, observation idempotency, audit, source health and Phase 4 queue handoff. The Windows PC runs an independent PHP 8.3 CLI worker, initiates HTTPS only, fetches only leased registered URLs, obeys robots/rate constraints, hashes exact stored bytes and retains a bounded encrypted spool until acknowledged. The PC has no inbound listener or MySQL credentials.

## Worker, job and lease model

Persist `queued`, `leased`, `processing`, `retry_pending`, `completed`, `blocked`, `manual_review`, `failed`, and `cancelled`. `scheduled_at` handles future work; `extracted`/`submitted` are attempt events, while `paused` is source configuration, not a job state. In one MySQL transaction, use `FOR UPDATE SKIP LOCKED` to select a due job, lock/recheck source admission, enforce per-origin/global slots, assign worker/attempt/fencing generation and lease, and write the attempt. Heartbeat and mutations require current attempt and lease; expired workers are fenced out and reaped to retry or terminal failure.

## Authentication and security

Use a single-use admin-provisioned worker activation code and worker-specific high-entropy bearer token stored hashed server-side and protected by Windows Credential Manager/DPAPI locally. Rotate/revoke per worker, scope credentials to claim/heartbeat/artifact/result/error/complete only, rate-limit by worker/endpoint/IP and never log secrets. Worker access is separate from Sanctum user sessions and cannot review, edit, verify, publish or administer. Validate TLS and source scope on both server and worker; block private/loopback/link-local/metadata IPs, validate all DNS answers and redirects, pin the validated address, and reject unregistered hosts/paths.

## API contract

Future worker endpoints under `/api/v1/crawler/...`: one-time `workers/activate`, `GET workers/me`, atomic `POST jobs/claim`, per-attempt heartbeat, bounded artifact `PUT`, result submission, safe error report and completion acknowledgement. Job/source administration and manual crawl requests remain under permission-gated admin routes. Use standard success/error envelopes, request IDs, protocol version header and idempotency keys. No job/claim/result API accepts an arbitrary URL or trusts worker-supplied source/job ownership.

## Idempotency and observation handoff

Claim replay identity is `(worker_id, claim_request_key)`; artifact upload is `(attempt_id, upload_key)` plus byte digest; Phase 4 observation identity stays `(source_id,idempotency_key)`, with the server deriving a stable job/attempt/content key; result/completion/error events have unique attempt-scoped request keys. Conflicting reuse returns 409. A 304 completes with no new observation. Successful bytes are staged, bounded, hashed, scope-checked and promoted to the private disk; in a durable DB transaction the server creates/returns the Phase 4 observation, links attempt/artifact and arranges processing through DB queue or transactional outbox. Phase 4 assigns the trusted receipt `observed_at`; PC fetch times stay in crawl-attempt history.

Current Phase 4 is JSON-only. The architecture specifies a backward-compatible Phase 4 parser-result adapter: retain the exact raw HTML/JSON/PDF artifact; store parser name/version/config and evidence pointers in existing `processing_runs.extracted_payload`; leave normalization, validation, duplicate/change detection, review and provenance inside Phase 4. Existing JSON processing and historical rows remain unchanged. This is the only planned Phase 4 integration seam; no separate crawler proposal/review model is introduced.

## Artifact, crawling and retry strategy

Use server-local private storage initially, matching Phase 4's current `local` disk. Do not select an unconfigured cloud provider. First artifact cap is the existing 4 MiB Phase 4 raw-payload cap; effective cap is the minimum of that value, measured PHP/proxy limits, memory headroom and quota-derived safe disk budget. Reject oversize data, never truncate. PDFs over limits and unsupported/scanned PDFs route to manual handling; archives are disallowed. Serve authorized artifacts only as downloads with `application/octet-stream` and `nosniff`.

Use only registered HTTPS origins by default, same-host/path-subtree scope, source-specific robots policy, one active request per origin until measured policy allows more, shared server-side rate scheduling, endpoint/worker limits, `Retry-After`, bounded exponential backoff with jitter and max attempts. 401/403/404, robots denial, TLS validation errors and unsupported content do not retry blindly. 429/5xx/network faults follow configured bounded policy. 304 updates source health/validators without an observation. HTML/JSON/JSON-LD use versioned generic/source-specific parsers; JS-required or unsupported content is manual/unsupported absent an approved browser strategy. No access-control evasion or AI.

## cPanel strategy and unresolved deployment gate

The design does not require Redis, Horizon, a permanent Laravel daemon, or a cPanel process manager. Use the database queue and a supported cron entry for `schedule:run`; scheduler creates jobs/reaps leases only. Invoke bounded database queue work using the cPanel cron/runtime supported by the account; the PC worker polls the API. Keep `public/` as the only web document root and private evidence below `storage/app/private`.

Before readiness can change to `READY`, record and verify this actual production account profile:

1. cPanel provider/account plan and PHP web/CLI versions/extensions; PHP must meet the Composer PHP 8.3+ requirement.
2. MySQL version (8+), DB queue availability/privileges and transaction/locking behavior.
3. Cron cadence and whether the host permits short bounded Laravel Scheduler and DB queue commands within execution limits.
4. PHP `upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time`, web server/proxy body cap and API timeout; prove the chosen raw cap and lease duration fit them.
5. Private storage quota/free capacity, writable path outside web root, backup/restore scope, retention budget and disk alerting.
6. HTTPS/TLS, API reachability, permitted outbound/inbound rules and request rate limits.

Those values cannot be inferred from repository config or local XAMPP PHP. The architecture defines how to derive caps and bounded work from them, but not whether the intended cPanel plan meets them. No external website or production server was contacted for this review.

## Failure recovery, health and monitoring

Leases fence stale workers. The worker spool survives a PC/network restart and reuses result/upload keys; it stops fetching after expiry. Server retries after transient failures, records blocked/manual outcomes and source cooldown, recovers orphan staging uploads only after a grace period, and reconciles accepted observations whose Phase 4 enqueue needs retry. Source health is a projection from attempts; worker ONLINE/IDLE/BUSY/STALE/DISABLED is derived from heartbeat, lease and admin state. Logs/metrics omit tokens, raw content and sensitive query parameters. Future admin monitoring covers active/failed/retry/blocked jobs, workers, source health, observation/processing backlog, uploads and audit events; no UI is implemented here.

## Test strategy

Use isolated MySQL 8 integration tests for concurrent claims, per-source slots, fencing, unique/idempotent writes and queue/outbox transaction behavior. Test auth/revocation/scopes; same/different-body upload and result retries; robots, redirects, source paths and SSRF/DNS rebinding; 200/304/404/429/5xx/timeouts/oversized responses; retry caps; MIME spoof/XSS/download authorization; network partition/spool recovery; parser version/evidence pointers; unsupported manual cases; health transitions; cPanel-like database-queue/cron execution. Use local fake HTTP/DNS fixtures only, never live university sites or primary DB.

## Implementation sequence

1. Freeze worker protocol, parser-result adapter and format support; review the Phase 4 compatibility seam.
2. Extend the existing source registry with strategy/access/scope/frequency config and least-privilege admin APIs.
3. Add worker provisioning, hashed credentials, middleware/scopes and rate limiting.
4. Add crawl jobs, attempts, idempotent scheduling/manual requests and append-only event history.
5. Implement MySQL atomic claims, origin slots, lease fencing, heartbeat and reaping; verify race tests.
6. Build Windows worker shell, credential storage, polling and recoverable spool.
7. Add HTTP/robots/SSRF/source-scope/rate-limit controls before enabling network fetches.
8. Add private bounded staged artifact upload, checksums, content detection, cleanup and protected retrieval.
9. Add observation transaction and durable Phase 4 parser/processing handoff.
10. Add bounded retries, conditional fetch, source/worker health and failure recovery.
11. Add generic/source-specific HTML/JSON-LD strategies, then explicitly supported PDF parsing; route other formats to manual review.
12. Add scheduler/manual priority and cPanel bounded queue execution.
13. Add permissioned monitoring, backup/restore, quotas, rollout and production hardening after cPanel preflight.

## Files created/modified

- Created `docs/architecture/phase-5-crawler.md`.
- Created `docs/setup/phase-5-architecture-review.md`.
- No existing file was modified by this review.

## Confirmation

- No application code changed.
- No migration changed or created.
- No crawler implemented.
- No external website contacted.
- Phase 4 implementation and files were preserved.

## Final status

`PHASE 5 STATUS: READY WITH CONDITIONS`

**Outstanding production gate:** the actual cPanel deployment profile listed above is unavailable, so production artifact/queue/lease limits cannot yet be verified. Obtain that account profile before production rollout. It does not block local PC-first server foundation implementation.
