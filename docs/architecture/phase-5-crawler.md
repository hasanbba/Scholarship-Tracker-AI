# Phase 5 — Crawler, Worker and Observation Ingestion

**Status:** Architecture baseline; implementation is blocked until the production cPanel resource profile is confirmed (see `docs/setup/phase-5-architecture-review.md`).
**Authority:** Phase 0 and Phase 0.1 define the worker boundary; Phase 2 owns the source registry and catalog; Phase 2.2 owns verification/publication; Phase 3 owns public visibility; Phase 4 owns observation, processing, review and provenance contracts.

## 1. Objective and invariants

Phase 5 schedules work from existing `scholarship_sources`, uses a separately operated Windows PC worker to fetch allowed official source URLs, preserves exact response bytes as private evidence, submits observations through authenticated HTTPS APIs, and hands accepted observations to Phase 4 processing. The Laravel application remains the system of record.

The worker has no MySQL credentials or network route to MySQL. It cannot edit catalog fields, proposals, review decisions, versions, verification records, or publication pointers. An observation is evidence only; it does not imply a change, approval, verification, or publication. Phase 5 does not call verification/publication services or expose crawler endpoints publicly without worker authentication.

## 2. Deployment architecture

```text
Production cPanel account                         Owner-operated Windows PC
┌─────────────────────────────────────┐           ┌────────────────────────┐
│ Laravel 12 API + MySQL 8             │  HTTPS    │ Worker CLI             │
│ Existing scholarship_sources         │◄─────────►│ Poll / lease / fetch   │
│ Crawl jobs, attempts, workers         │           │ Robots / parser        │
│ Private artifact storage              │           │ Encrypted local spool  │
│ DB queue + cron-driven scheduler      │           └────────────────────────┘
│ Phase 4 processing/review             │
│ Phase 2.2 verification/publication    │
└─────────────────────────────────────┘
```

The PC initiates outbound HTTPS connections only: to the application API and to explicitly allowed public source origins. No inbound listener, shared database, browser session, or public crawler UI is required. The worker is a separately versioned PHP 8.3 CLI package for Windows, launched by Task Scheduler at startup with restart-on-failure and a bounded polling/backoff loop. PHP 8.3 matches the repository runtime contract; package the worker independently from Laravel so it cannot load app secrets or DB configuration. The worker's state is limited to a protected credential and durable upload spool.

## 3. Server and PC responsibilities

| Production server | Windows worker |
|---|---|
| Canonical source configuration, source eligibility, schedules, manual crawl requests | Poll and atomically claim a job; obey its lease and source policy |
| Worker registry, hashed credentials, revocation and API authorization | Fetch only the supplied registered URL; enforce robots and redirects |
| Crawl job/attempt state, leases, retries, audit and source-health projections | Capture exact entity bytes, classify content, calculate SHA-256, preserve locally until acknowledged |
| Per-source/global concurrency and rate admission | Send heartbeat, artifact/result, safe error category, and completion request |
| Private artifact acceptance and checksum verification | Retry transient transport failures with the same operation key; never invent a new job/result on timeout |
| Phase 4 processing queue and all canonical/review/version state | No direct canonical writes, verification, publication, or database access |

The server is authoritative for job state, retry timing, quotas, worker revocation, source configuration and observation identity. The worker is not trusted to assert authorization, target ownership, accepted URL scope, successful job completion, or verification.

## 4. Existing Source Registry contract

The registry is the existing `scholarship_sources` table and `ScholarshipSource` model. Phase 2 makes a source scholarship-scoped or university-scoped; it already stores `scholarship_id`, `university_id`, `source_type`, `source_name`, canonicalized `source_url`, unique `source_url_hash`, `is_primary`, `status`, and `notes`. Phase 5 must extend this registry rather than create another source catalog. `is_primary` remains an evidence/publication property, not crawl priority.

The implemented registry does **not** currently store crawl method/frequency, JS requirement, allowed URL scope, or robots/access policy; it has no crawler health data. Additions should separate:

* **Administrative configuration on the existing source:** crawl method (`http` initially), frequency mode/interval, JS-required/manual flags, explicitly allowed hosts/path prefixes, robots policy, per-origin delay/concurrency, priority, maximum artifact size override, enabled state (reuse `status`), and admin notes. Defaults are conservative; no wildcard domains. Only permissioned admins may change these fields.
* **Runtime projection/history:** latest success/failure, HTTP status, latency aggregate, validator headers, consecutive failures, content hash/time, blocked/parser/upload counts and derived health belong to one source-health projection plus immutable crawl attempts/events. Do not overwrite `notes` or add duplicate source rows to store operational state.

`status=active` is necessary but insufficient for a crawl: the source must also have a supported strategy, allowed robots policy, valid public destination scope, and no active source pause/cooldown. Inactive sources receive no new claim. Existing `source_url` is the canonical root; default scope is same HTTPS origin and its path subtree. Cross-host redirects, subdomains, sibling domains or external links require explicit source-level allow-list entries and human administration.

## 5. Crawl job model

Add job/attempt tables without replacing `scholarship_sources`, `raw_observations`, or `processing_runs`:

* `crawl_jobs`: UUID or bigint ID; `source_id`; exact requested URL and URL hash; schedule/manual request identity; priority; `scheduled_at`; state; attempt count/max; current attempt/worker; lease generation/expiry; created/started/completed timestamps; terminal category; accepted observation ID (nullable); result key; and timestamps.
* `crawl_attempts`: job ID, monotonically increasing attempt number and random attempt ID; worker ID; claim key; lease generation; start/end/heartbeat/lease expiry; request/result keys; HTTP status, duration, response type/size/hash/ETag/Last-Modified; safe error code; retry time; staged artifact ID; observation ID; attempt outcome. Attempts are retained for audit and diagnosis.
* `crawl_artifacts`: attempt ID, upload key, content hash, byte size, detected media type, private opaque storage key, state (`staged`, `verified`, `linked`, `orphaned`), and observation link once committed. A unique attempt/upload key makes upload retry safe; a content-addressed storage key avoids duplicate bytes.
* `crawler_workers` and `crawler_worker_tokens`: stable worker ID, installation/hostname label, version/protocol/capabilities, enabled flag, last heartbeat/current attempt (projections), token hash/fingerprint, scopes, expiry/rotation/revocation metadata. Never store a plaintext token.
* `crawler_job_events`: append-only state, lease, credential, retry and administrative events with actor/worker, correlation ID and redacted details. Laravel JSON logs remain for diagnostics; they are not the audit system of record.
* `crawl_source_health`: one current projection per existing source; immutable attempt/event history remains the source of reconstruction.

The source/schedule/manual request key is unique for its occurrence. No table or endpoint permits a worker to request an arbitrary URL or arbitrary source ID.

## 6. Job lifecycle

Use only these persisted job states:

```text
queued → leased → processing → completed
   ↑          │          ├→ retry_pending → queued (when due)
   │          │          ├→ blocked
   │          │          ├→ manual_review
   │          │          └→ failed (permanent / attempts exhausted)
   └──────────┴── expired lease recovery
queued/retry_pending → cancelled (admin)
```

`scheduled_at` expresses a future due time; a separate `pending` state is unnecessary. `extracted` and `submitted` are attempt milestones/events, not competing job states. `paused` is represented by disabling/pausing the existing source configuration and preventing claims; queued jobs remain visible and are not silently deleted. `blocked` means an access-policy block requiring a cooldown or human action. `manual_review` means fetch/evidence is retained but the strategy or access outcome needs an admin. `completed` covers an accepted observation or a valid conditional `304` no-change completion. `failed` is terminal only after the bounded retry policy is exhausted or a permanent failure is recorded.

## 7. Atomic claiming and leases

Expose one `POST /api/v1/crawler/jobs/claim` operation, never a poll-then-claim pair. In a single MySQL transaction, select the highest eligible due job using `SELECT … FOR UPDATE SKIP LOCKED`, lock its source admission row, re-check source enablement/cooldown/robots eligibility, per-origin concurrency and global limits, then assign worker, attempt ID, incremented fencing/lease generation, claim time, expiry and attempt row before commit. MySQL 8 is already the supported database baseline. A unique `(worker_id, claim_request_key)` makes retry after a lost claim response return the same attempt; the worker creates a fresh claim key only for a new claim operation.

The lease token is random per attempt and returned once; only its hash is stored. Heartbeat and all result/error/completion mutations require worker ID, attempt ID, matching lease generation/token, and a still-current lease. Heartbeat may extend only the current lease. A worker whose lease expired is fenced out even if it resumes; stale submissions return a conflict and cannot complete or replace the recovered attempt. Lease duration must exceed configured fetch timeout + maximum artifact transfer time + API timeout + heartbeat slack. These values must be calculated from deployment limits, not guessed.

On expiry, a reaper locks the job/attempt, marks the attempt `lease_expired`, appends an event, releases the per-source slot, and moves the job to `retry_pending` with server-calculated `next_attempt_at` or terminal `failed` at max attempts. The reaper is idempotent. Source slot accounting and claim happen under the same source-scoped lock so several workers cannot exceed the configured origin concurrency.

## 8. Worker identity and lifecycle

An admin provisions an installation using the future permission-gated admin API; a single-use, expiring activation code binds a worker-generated installation UUID to a label and declared capability/version. Activation over HTTPS returns one high-entropy worker bearer token once. Worker identity is a random stable ID, not a hostname or a shared fleet secret. Hostname is informational and may be changed; it is not an authorization key.

Store only a cryptographic hash/fingerprint of the token, scopes, created/expiry/revoked/rotated times and last-used time. Use one active credential per installation, rotate with an administrator-authorized operation, and revoke immediately on disable/compromise. Keep any brief overlap during rotation configurable and shortest possible; old tokens cannot claim new work after cutover. Store the token using Windows Credential Manager/DPAPI protected storage with restrictive ACLs, never in source, command arguments, plaintext config, logs or spool manifests.

Worker health states are derived: `ONLINE` if an authenticated heartbeat is fresh; `BUSY` with an active lease; `IDLE` otherwise; `STALE` after the configured heartbeat/lease freshness bound; `DISABLED` by admin. Persist heartbeat projection on the worker row; append an event for state transitions/anomalies, not every poll.

## 9. Authentication and security

Keep Sanctum cookie sessions for the first-party SPA and do not add worker identities as admin users. Current `User` does not use `HasApiTokens`, `auth.php` has only the web guard, and current API admin routes use `auth:sanctum` plus permission gates. Add dedicated worker-token middleware/table and a separate worker principal/context; this is cleaner than granting a Sanctum user token crawler permissions and prevents accidental use of user/admin scopes. The worker principal receives only claim, heartbeat, artifact/result submission, error and completion capabilities. It has no review, catalog, user, verification, publication or admin permission.

Require valid TLS certificates and HTTPS for every API call; reject plain HTTP at the edge/API. Use random bearer tokens over TLS, rate-limit by worker and endpoint/IP, constant-time hash comparison, token revocation/expiry, strict Form Requests, request/correlation IDs, job/attempt lease binding, and idempotency keys on mutations. Since mutations are idempotent and lease-bound, a separate request-signature protocol is not required initially. Never log Authorization/cookie headers, upload bytes, or private paths. The server database credentials stay local to the Laravel host and must not be provisioned to a worker.

Treat a compromised PC/token as able to submit fake evidence and consume only its assigned jobs within quotas. It cannot select arbitrary sources, hostnames or paths; it cannot write canonical state or approve evidence. Revoke it, invalidate leases, quarantine its incomplete uploads, and audit the event. Human review and Phase 2.2 verification remain the final gates.

## 10. Internal API contract

All routes use `/api/v1/crawler/...`, JSON success/error envelopes matching `ApiResponse`, authenticated worker middleware (except one-time activation), no browser session/CSRF, and explicit protocol version header `X-Crawler-Protocol: 1`. Unsupported protocol versions receive a standard `409`/upgrade-required error. Worker APIs are distinct from the existing session-protected `/api/v1/admin/data-quality/...` routes; workers must never call those admin routes.

| Endpoint | Responsibility |
|---|---|
| `POST /workers/activate` | Exchange a one-time activation code for worker ID and token; rate-limited; never self-register without admin provisioning. |
| `GET /workers/me` | Return worker ID, protocol compatibility, enabled state, capabilities and polling/backoff guidance; no secrets. |
| `POST /jobs/claim` | Atomic claim with idempotency key; returns one job or `data: null`, safe source scope/config snapshot, attempt ID, lease generation/token and expiry. |
| `POST /jobs/{job}/attempts/{attempt}/heartbeat` | Report liveness/progress and renew the current lease only if fencing fields match. |
| `PUT /jobs/{job}/attempts/{attempt}/artifact` | Upload bounded raw bytes to private staging; require upload idempotency key and declared hash; server streams/hash-checks and returns opaque upload ID. |
| `POST /jobs/{job}/attempts/{attempt}/result` | Submit response metadata, upload ID/hash, parser name/version/config version, evidence locators and idempotency key; server validates ownership/scope and creates observation + Phase 4 processing handoff. |
| `POST /jobs/{job}/attempts/{attempt}/errors` | Report allow-listed error category, HTTP status, safe short diagnostic and idempotency key; server owns retry decision/time. |
| `POST /jobs/{job}/attempts/{attempt}/complete` | Acknowledge server-accepted result or 304 outcome; replay returns the same result. Worker cannot claim completion before acceptance. |

Job creation, source edits, worker provisioning/revocation, pause/resume, monitoring and manual crawl are admin-only operations under `/api/v1/admin/...` with narrow permissions. Manual crawl inserts an ordinary job and goes through the same due time, source policy, rate checks, lease and result pipeline.

Requests include `X-Request-ID`; every mutating operation has an `Idempotency-Key`. Responses return job/attempt IDs, server state, next permitted poll/retry time, and correlation ID, never internal storage paths or other jobs. Validation errors use `{"success":false,"message":"Validation failed.","errors":{}}`; success uses the existing success/message/data envelope.

## 11. Idempotency rules

| Operation | Identity and replay behavior |
|---|---|
| Claim | `(worker_id, claim_request_key)`; same key returns the same attempt/lease, never a second job. New logical poll uses a new random key. |
| Artifact upload | `(attempt_id, upload_key)` plus exact SHA-256/size; identical retry returns same upload ID; different bytes with same key are `409`; object path is content-addressed. |
| Observation | Phase 4 identity `(source_id, idempotency_key)`; server derives key from job ID + attempt ID + raw content hash. Same receipt/content returns existing observation; reused key/different URL/hash conflicts. A later attempt/capture gets a distinct key even if bytes match. |
| Result/handoff | `(attempt_id, result_key)` and immutable request digest; retries return the same observation/processing handoff; divergent body conflicts. |
| Completion | `(attempt_id, completion_key)`; identical repeat returns the recorded terminal response and does not increment counters or create new observations. |
| Error | `(attempt_id, error_event_key)`; repeated report returns existing event; retry schedule is updated once. |
| Manual/scheduled job creation | Admin request key or unique `(source_id, schedule_slot)`; scheduler overlap cannot duplicate one occurrence; later occurrences remain distinct. |

Use unique constraints plus transaction/row locks, not cache-only idempotency. Retain result keys for at least as long as job and evidence history.

## 12. Observation and Phase 4 handoff

Keep the Phase 4 `raw_observations` row as exact captured source bytes with existing `source_id`, exact `observed_url`, SHA-256, private `artifact_ref`, media type/length, access status, producer type/ref and idempotency key. `producer_ref` is the stable crawl attempt ID; the job/attempt FKs remain Phase 5-owned. Phase 4 assigns the trusted receipt `observed_at`; actual worker fetch start/end timestamps remain on the attempt to avoid trusting a PC clock. Do not reinterpret or replace Phase 4's receipt timestamp.

Successful HTTP response flow: upload exact entity bytes → server verifies scope, length and SHA-256 and finalizes private object → transaction inserts/finds the Phase 4 observation and links artifact/job/attempt → durable Phase 4 processing handoff → mark the attempt accepted/completed. A 304 creates no raw observation; it completes as unchanged and retains the previously linked evidence. Failed/blocked responses create attempt/error history but no successful processable observation. The worker never writes observations directly to MySQL.

**Parser compatibility gate:** Current Phase 4 implementation has only `JsonCandidateExtractor`; `/admin/data-quality/observations` accepts a JSON `raw_payload` capped at 4 MiB and is admin-permission protected; its processor reads the artifact and parses JSON synchronously. It cannot currently parse an HTML/PDF response or accept a separate versioned worker parser result. Phase 5 must therefore add a backward-compatible parser-result adapter to the Phase 4 processing boundary: preserve the original observation artifact, persist parser name/version/config and field evidence in the existing `processing_runs.extracted_payload`, then let Phase 4 normalization, validation, duplicate/change detection and review remain authoritative. Keep the existing JSON path and all existing rows unchanged; do not add a parallel proposal/review schema. This adapter is a documented integration change and must receive a focused Phase 4 compatibility review before Phase 5 code starts.

Use a durable DB queue handoff after observation commit: create/dispatch the processing job in the same transaction when the configured queue shares the MySQL connection, or use a transactional outbox if it does not. Do not accept an observation and rely on an untracked best-effort in-memory dispatch. Queue failure leaves the observation intact and discoverable for retry; same result key retries the handoff, not the capture.

## 13. Artifact storage strategy

**Initial choice: server-local private storage (Option A), behind Laravel's storage abstraction.** The actual Phase 4 deployment uses the `local` disk rooted at `storage/app/private`; Phase 0 requires evidence outside the public web root. The repository also has an optional S3 adapter but no configured/selected provider or evidence that cPanel supports a chosen external service. Do not introduce a cloud dependency without an operational decision.

The upload endpoint writes to a random staging object, streams bytes while hashing, verifies the declared size/hash, then atomically promotes to a content-addressed private path. The DB stores only the opaque server-generated key. Do not accept a client path or `artifact_ref`. Keep files outside `public/`, never create a public storage link for them, and retrieve through an authorized controller as attachment with `application/octet-stream` and `X-Content-Type-Options: nosniff`.

The current Phase 4 request cap is 4 MiB; use 4 MiB as the first raw-artifact cap (not a new arbitrary value) and keep a per-source lower cap. Effective limit is the minimum of this cap, PHP upload/post limits, reverse-proxy/cPanel request cap, configured memory headroom, and the quota-derived safe remaining disk budget. A response above the cap is not truncated; report `oversized`/manual review. Daily upload budget and spool quota are calculated from measured account quota, backup reserve and retention policy. If this envelope cannot retain evidence safely, the source is manual/unsupported until storage is expanded. PDF size beyond the initial cap is not accepted by increasing limits blindly.

Option B (object store) is a later deployment choice only if measured cPanel quota/backup objectives require it; choose a provider, private bucket/access policy, encryption and restore path explicitly. Option C hybrid staging is unnecessary initially and complicates cleanup. Storage migration must preserve content hash and opaque references.

## 14. Artifact security and retention

All uploads are untrusted. Accept only a bounded regular file upload; reject archives and executable types; detect MIME from bytes and compare with declared type; normalize names internally; never extract archives; guard path traversal by never using caller filenames or storage keys. Limit request body before parsing, upload size before reading, and parser CPU/memory/time. Keep staging files quarantined until checksum, job/lease, media type, URL scope and worker authorization pass. Clean only unlinked staging objects after an auditable grace period; never delete an object referenced by an observation, processing run, review, provenance or version.

HTML/PDF are served only as authenticated downloads and never inline; use attachment disposition, `nosniff`, no public URL and no browser rendering on the app origin. PDFs use a vetted parser with decompression/object-count limits in a restricted process; scanned/unsupported PDFs become `manual_review`. No ZIP/archive support in Phase 5 v1. Hash exact stored entity bytes; record detected media and size. Backup verification and restore are required before production use.

Raw artifact retention follows Phase 4's evidence lifetime: retain while any observation/run/proposal/review/provenance/version depends on it, then only delete by an explicit legal/retention policy with audit and proven backup coverage. Keep crawl attempts, decisions, worker credential events and audit history longer than temporary spool/heartbeat data. Local PC spool is encrypted at rest by OS volume protection, ACL-limited, quota-bounded and deleted only after server acknowledgment or a documented expired-spool policy; it stores no DB credentials.

## 15. Robots and access rules

Admin validates source legitimacy/terms before enabling it. Fetch `robots.txt` for the origin, cache policy with fetch time and content hash, and evaluate each requested path. A disallowed path, explicit terms restriction, 401/403, CAPTCHA challenge or provider block produces `blocked`/`manual_review`, logs a safe reason, updates health/cooldown and creates no automatic retry loop. Respect `Retry-After` and per-source terms. A denied source stays out of claims until an authorized admin rechecks and enables it.

No CAPTCHA/anti-bot bypass, stealth, proxy rotation, fingerprint spoofing, authentication circumvention, or rate-limit evasion. Do not follow third-party links automatically. For robots retrieval errors, fail closed for a source whose policy is unknown until a human decides, except where a documented source-specific public policy permits a bounded recheck.

## 16. Source/worker/API rate limits

Enforce rate at three layers: one server-side per-origin admission lock/token schedule shared by all workers; per-worker claim/heartbeat/upload/error quotas; and global server active-lease/upload/queue quotas. A source has a configurable minimum inter-request delay, max concurrent leases (initially one), daily/request budget and cooldown. Resolve host aliases to the same origin key. `429` always honors `Retry-After`; no worker-local setting can override server policy. Worker requests use independent endpoint limiters (claim, heartbeat, upload, error/result); upload is byte-weighted. Return standard 429 envelope and `Retry-After` header.

Set numeric rates and global limits from source terms, expected job volume and cPanel CPU, DB, bandwidth and storage measurements. Until configured, use one active request per source and no burst; disabled limits are not treated as unlimited. Apply fair queue aging so low-priority sources are eventually considered.

## 17. Retry and backoff

Server, not worker, sets retry count/time. Retry transient DNS/network failures, timeouts, resets, 408, 429, and 500/502/503/504 with exponential backoff plus bounded random jitter; honor `Retry-After` but cap it by admin policy. Cap attempts and retry horizon in source/global policy. Permanent 400/401/403/404/410, invalid source scope, unsupported type, robots denial, TLS certificate error, and repeated oversized response do not retry automatically; route to failed or manual review. A 429/403/block pauses/cools down that origin across all workers. Distinguish permanent publisher absence from server/network faults in reason codes.

Worker retries of the API use the same idempotency key and stored spool bytes. If the server is unreachable, retain the spool and renew only while its lease remains valid; after lease expiry stop network fetches, preserve the spool, and request a new job only after reconciliation. Backoff parameters are deployment/source configuration derived from legal policy and measured uptime, not hard-coded retry loops.

## 18. HTTP fetch behavior

Use a maintained HTTP client with TLS verification enabled, explicit connect/request timeouts, bounded redirect count, accepted schemes/ports, maximum entity bytes, restricted content types, compressed/decompressed size limits, supported encoding handling, a descriptive project User-Agent/contact URL, and safe response metadata. Never disable TLS verification. Reject credential-bearing URLs, malformed/unsupported schemes and non-HTTP(S) protocols. Stream the response to protected local spool while hashing; do not buffer unbounded bodies. Redirects are evaluated one hop at a time under SSRF and source-scope rules. Do not forward cookies, Authorization headers or conditional validators across origins.

Conditional requests use source-scoped `ETag` and `Last-Modified` validators recorded from the last successful response. Send `If-None-Match` / `If-Modified-Since` only to the same approved origin. A valid 304 updates check/success health and completes the attempt without a new observation or Phase 4 run; retain the last successful observation and validators. A 304 without an issued validator is a protocol error, not evidence.

## 19. SSRF and URL-scope protection

The server creates jobs only from an active registry URL or an admin request validated against it. Both server admission and PC worker validate before each request and after every redirect: HTTP(S) only; no userinfo; standard ports unless explicitly reviewed; normalized IDNA hostname; exact allowed host by default; allowed path prefix with segment boundary; no downgrade from HTTPS; no unregistered external host. Reject localhost, loopback, private, link-local, multicast, unspecified, reserved IPv4/IPv6, IPv4-mapped IPv6, and cloud metadata destinations. Resolve DNS immediately before connect, reject if any answer is disallowed, pin connection to the validated address while preserving TLS hostname/SNI, and repeat on redirects to limit DNS rebinding. Never follow page links as crawl jobs unless separately registered.

Source scope configuration uses explicit host entries and normalized path prefixes; no wildcard suffix matching. Existing Phase 4 ingestion checks only host suffix and does not check path or DNS/IP. The worker API must perform stricter checks before it calls Phase 4 intake, and the compatibility bridge must independently enforce job/source/URL ownership; do not assume the Phase 4 admin intake alone is SSRF protection.

## 20. Static, JavaScript and document strategies

Phase 5 v1 supports bounded static HTML, structured JSON/JSON-LD and direct PDF/download capture where a parser is registered and tested. A source's `crawl_method` chooses a generic HTML/JSON/PDF strategy or a versioned source-specific strategy. JS-dependent sources are marked `JS_REQUIRED`; no headless browser is required by default. Without a reviewed browser implementation, mark them `UNSUPPORTED`/`MANUAL`, do not attempt bypass. A PDF is retained as evidence; only supported text PDFs enter a versioned PDF parser, while scanned/complex PDFs go to manual review. Parser failures never become empty scholarship data or canonical updates.

## 21. Parser boundary and versioning

Separate `HttpFetcher`, raw artifact store, parser strategy, and Phase 4 pipeline. Parser name, semantic version and configuration version are returned with each result and stored in `processing_runs`; source-specific strategy selection is server-approved and allow-listed. A generic parser is tried only for declared supported formats; source-specific parsers are small registry plugins, not a giant university switch statement. Parser versions are immutable; reprocess creates a new Phase 4 processing run and never changes a raw observation or prior run.

Parser output is an extracted claim with field-level JSON pointer/page/anchor evidence into the exact observation artifact. It may not set canonical rows. The Phase 4 adapter records that claim in the existing `extracted_payload`, then Phase 4 owns normalization, validation, duplicate/change detection, review, versioning and provenance. A parser's confidence is advisory only. Parser configuration changes are versioned and auditable. No AI provider or extraction calls are included.

## 22. Scheduling, deadlines and priority

The server scheduler evaluates enabled sources and inserts ordinary `crawl_jobs` based on admin-selected HIGH/MEDIUM/LOW/MANUAL frequency policy and per-source interval; actual intervals are deployment policy, not universal constants. The scheduler is idempotent by `(source_id, schedule_slot)` and never fetches. Admin “crawl now” inserts a normal job.

Effective priority combines configured source priority, job age (anti-starvation), due time, latest successful content-change signal, and proximity to the nearest applicable non-expired cycle deadline when an existing source can be related to that scholarship. Deadline proximity raises scheduling priority only; it never edits scholarship/cycle dates or publishes data. Failing/blocked sources are cooled down and do not gain repeated high priority. The exact weighted/aging function is configurable, deterministic and tested; no opaque AI/quality score affects it.

## 23. Content hashing and source health

Calculate SHA-256 over exact bytes saved as artifact. If it matches the most recent successful content hash for that source, record the attempt as unchanged and still create a new observation only for a successful distinct capture, following Phase 4's history/idempotency semantics; conditional 304 creates no capture. A changed hash is only a reason to hand off evidence; it does not equal a canonical scholarship change. Phase 4 decides whether normalized fields differ.

Source health states: `HEALTHY`, `DEGRADED`, `FAILING`, `BLOCKED`, `PAUSED`. Derive from latest success, bounded consecutive failure counts, HTTP status, latency, robots/access blocks, parser failures, upload/handoff failures and last content change. `PAUSED` follows admin disable; blocked access remains until explicit cooldown/review. Store projection per source and retain attempt history. Avoid raw response bodies and secrets in health data.

Worker health is derived from authenticated heartbeat freshness, assigned lease, worker version, enabled state, recent success and failures. Store a current projection and sparse transition history. Never treat a fresh heartbeat as proof that a job completed.

## 24. Failure recovery and network partition

| Failure | Recovery |
|---|---|
| PC shutdown/crash | Lease expiry fences the old attempt; server schedules retry; restart recovers local spool and reconciles attempt IDs before fetching new content. |
| Internet outage | Keep exact artifact/manifest in bounded protected spool; retry submission with same keys; do not re-fetch until accepted or expired/reassigned. |
| Laravel/API unavailable | Backoff; retain spool; stop when lease expires; later reconcile by job/attempt/result key. |
| Worker crashes during fetch | Discard incomplete temp file after restart or resume only if protocol supports verified ranges; never submit partial bytes. Server lease reassigns. |
| Upload fails halfway | Staging object is not linked; retry same upload key/hash; server verifies final size/hash and cleans expired partial stage safely. |
| Upload succeeds, result/completion response lost | Repeat same result/completion key; server returns original observation/result without duplication. |
| Result succeeds, Phase 4 enqueue fails | Durable outbox/DB queue keeps pending handoff; reconciliation retries; observation/artifact remain intact. |
| Duplicate result arrives | Unique idempotency constraints return existing receipt; mismatched digest conflicts and is audited. |
| Stale worker submits after reclaim | Fencing token/attempt mismatch rejects it; preserve local spool for admin reconciliation, never let stale worker overwrite current attempt. |

Use encrypted filesystem spool plus append-only JSON manifests and atomic file rename; no local DB is needed. Spool size, age and free disk thresholds are configured from the PC's measured disk. When full, stop claiming new jobs and alert locally. Remove the local copy only after server acknowledgment and verified server-side hash; expired/unacknowledged evidence requires an explicit retention decision, not silent cleanup.

## 25. cPanel queue, scheduler and deployment strategy

Actual code currently defaults to Laravel's database queue; `config/queue.php` sets `after_commit=false`. A jobs migration exists, but there is no crawler job/worker, scheduler task, or queue worker deployment in the checkout. `routes/console.php` has only the sample `inspire` command. No Redis or persistent process manager should be assumed.

For cPanel, use database-backed queue records and short bounded server queue-worker invocations launched by the provider-supported cron, plus `php artisan schedule:run` on the supported minute cadence. Scheduler only creates due jobs and expires leases; it never fetches. Do not require Horizon, Redis, daemon supervisors or always-on PHP processes. Worker polling remains on the PC. Use DB locks/unique constraints for one scheduler and queue/job overlap, with one canonical cPanel cron entry. Run Laravel from the protected application root with a restricted MySQL user and PHP 8.3+, MySQL 8+; configure HTTPS and document-root so `public/` alone is web reachable; keep `storage/app/private` writable but not web-served.

Production activation requires verifying actual PHP CLI/web version and extensions, MySQL version, cron cadence, queue invocation limits, max execution time, memory, upload/post/proxy caps, available private disk quota, backup/restore coverage, TLS, and whether the hosting plan permits the needed DB queue/scheduler frequency. The project specifies none of these account-specific cPanel limits. This is the outstanding readiness blocker; do not assume a shared host supports them.

## 26. API compatibility, observability and admin monitoring

Keep `/api/v1/crawler/...` stable. Protocol 1 is additive and backward-compatible: optional fields may be added; meaning/required fields cannot change under the same protocol. Server advertises minimum/maximum supported worker protocol and version; reject too-old clients before claim, allow staged upgrades, and retain the prior version during rollout. A worker upgrade cannot strand an active lease; allow it to finish or let it expire before disabling the old version.

Record structured metrics/events for jobs created/claimed/completed, lease expiry, retry/failure category, blocks/HTTP status, fetch duration, response/artifact bytes, hash-changed/unchanged, submission/handoff outcome, per-source/worker rate limits, heartbeat freshness and parser result. Include job/source/worker/attempt/correlation IDs. Redact credentials, tokens, cookies, query secrets and raw contents; avoid logging full URLs where query parameters may contain secrets. Use sampled latency/size metrics and bounded retention.

Future admin monitoring is permission-gated and server rendered or existing admin SPA as a later explicit UI phase; it shows worker state, active/expired jobs, retries/failures, blocked/manual sources, source health, observations/handoff backlog, upload failures, and audit trail. It provides safe retry/cancel/disable operations with audit. Phase 5 architecture creates no UI now.

## 27. Payload limits and retention

Use 4 MiB raw artifact as Phase 5's initial maximum because the current Phase 4 intake already caps raw payload at 4 MiB. The multipart request cap must account for metadata overhead and remain within measured PHP/web-server limits. Extracted JSON is separately bounded; its maximum is derived from allowed fields/rules and request memory limits. Per-day upload budget derives from quota, backups and retention; global concurrency derives from host CPU/DB capacity. Oversize content is not silently truncated or retried indefinitely; retain a safe failure record and request manual handling.

Keep raw evidence while referenced by Phase 4 processing/review/provenance/version history. Keep jobs, attempts, decisions and audit events according to the product evidence/financial/legal retention schedule; no such schedule is set by this repository, so this must be an admin deployment configuration before launch. Worker heartbeat projection is mutable; sparse transition events are retained, not every heartbeat. Failed jobs and parser errors are retained in attempt/event history. PC spool is temporary and cleared only after acknowledged upload subject to a configured maximum age/quota.

## 28. Manual operations and source priority

Admin source edits and manual crawl requests require explicit `sources.manage`/`crawler.manage`-class permissions separate from review/verification/publication. A manual request has a request key and inserts a normal job with a reason/actor event. It obeys robots, URL scope, per-source slots, quotas, retry policy and Phase 4 handoff.

Priority uses configured source tier, due schedule, aging, deadline proximity, last content change and source health. Use aging/fairness so low-tier sources are not starved; blocked/failing sources are excluded or cooled down rather than repeatedly promoted. Priority never bypasses source scope, host rate, worker quotas or human review.

## 29. Test strategy

Integration tests use isolated MySQL 8+ for `SKIP LOCKED`, unique keys, source-scoped claims, lease fencing and durable queue transaction behavior. Unit tests cover parser adapters, URL canonicalization, SSRF address classification, robots rules, backoff, content hashing and source-priority fairness. Never use the primary DB or real external sites in automated tests; use local fake HTTP servers, DNS fixtures and synthetic source content.

| Area | Required cases |
|---|---|
| Auth/provisioning | Valid, invalid, expired, rotated, revoked, disabled worker; no self-registration; user/admin session cannot access worker routes; worker cannot access admin/review/verify/publish. |
| Claims/leases | Single and concurrent workers; same claim key replay; two workers racing; source concurrency; heartbeat renewal; lease expiry/reclaim; stale fencing token rejection; max attempts. |
| Idempotency | Duplicate artifact upload, mismatching upload body, observation retry, same content later attempt, result/completion retry after lost response, duplicate error event, scheduler/manual job replay. |
| Source/SSRF | Outside host/path, unregistered subdomain, redirect outside scope, downgrade, localhost/private/link-local/IPv6-mapped/metadata, DNS rebinding, userinfo and bad port. |
| Fetch/policy | 200, valid/invalid 304, 404, 401/403, 408, 429 + Retry-After, 5xx, timeout/reset/DNS failure, TLS error, malformed encoding, redirect cap, oversized compressed/decompressed body. |
| Robots/rate | Allowed/disallowed path, robots unavailable, terms/manual block, concurrent source jobs across workers, cooldown, rate/burst quotas, fairness/no starvation. |
| Artifacts | Exact checksum, content-addressed repeat, wrong hash, MIME spoof, traversal filename, executable/archive rejection, size caps, unauthorized download, XSS-safe attachment, orphan cleanup cannot remove linked evidence. |
| Handoff | Valid observation linked to attempt; invalid scope rejected; JSON parser compatibility; HTML parser evidence locator; PDF/manual route; Phase 4 processing enqueue replay; no canonical or publication mutation by worker. |
| Health/recovery | Heartbeat, stale/disabled worker, server/network outage, PC restart/spool recovery, upload succeeded/result lost, result succeeded/complete lost, outbox replay, source health transitions. |
| Deployment | cPanel-like no-Redis/database-queue profile, one-minute scheduler, bounded queue execution, storage outside public root, configured PHP/proxy upload and timeout limits. |

## 30. Safe implementation sequence

1. **5.1 Contract and compatibility gate:** agree worker protocol, Phase 4 parser-result adapter and supported v1 formats; preserve the JSON parser contract.
2. **5.2 Source configuration:** extend the existing registry with allow-scope, strategy, frequency and access controls; admin permission/audit.
3. **5.3 Worker identity/auth:** provisioning, hashed token lifecycle, scopes, rate limits and worker-only middleware.
4. **5.4 Job/attempt schema and lifecycle:** idempotent admin/scheduler creation and job event history.
5. **5.5 Atomic claims/leases:** MySQL concurrency, source slots, fencing, heartbeat and reaper; test races first.
6. **5.6 Windows worker shell:** secure credential store, claim/heartbeat, bounded client retries and durable spool.
7. **5.7 Fetch/robots/URL security:** SSRF/DNS pinning, TLS, redirects, robots and source/global throttling before enabling source fetches.
8. **5.8 Private artifact upload:** staged streaming hash verification, MIME/size checks, idempotency, cleanup and authorized retrieval.
9. **5.9 Observation + Phase 4 adapter:** transactional linking, parser metadata/evidence pointers, existing Phase4 normalization/validation/detection handoff via durable queue/outbox.
10. **5.10 Retry and conditional fetch:** bounded retry schedule, 304 semantics, failure recovery and source health projection.
11. **5.11 Parser strategies:** generic static HTML/JSON-LD; then explicitly supported PDF; unsupported/JS sources stay manual.
12. **5.12 Scheduler and manual requests:** due-job creation, fair priority, deadline signals, cPanel cron/queue execution.
13. **5.13 Monitoring and production hardening:** admin monitoring, audit, backup/restore, quotas, cPanel preflight, security review and staged worker rollout.

Do not enable production sources until the final cPanel preflight and end-to-end approval/publication boundary tests pass.
