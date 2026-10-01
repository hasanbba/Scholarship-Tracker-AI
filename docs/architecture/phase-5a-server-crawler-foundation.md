# Phase 5A: Server Crawler Foundation

## Status and boundary

Phase 5A implements Laravel's server-side API and persistence foundation for the independent Windows PHP 8.3+ CLI crawler. The worker communicates over HTTPS and has no Laravel runtime, `.env`, database credentials, or MySQL connection. Laravel remains authoritative for source configuration, jobs, attempts, leases and audit history.

This batch does not fetch pages, read robots.txt, perform DNS/SSRF checks, upload artifacts, submit observations, or parse HTML/PDF. Production cPanel limits remain a later deployment gate; this local foundation follows the approved PC-first model.

## Source configuration

`scholarship_sources` remains the only source registry. The additive migration adds:

| Field | Purpose |
|---|---|
| `crawl_enabled` | Explicit opt-in; defaults false for existing sources. |
| `crawl_method` | `http` or `manual`; only `http` jobs are claimable. |
| `crawl_frequency` | `manual`, `daily`, `weekly`, or `monthly`; configuration only in 5A. |
| `crawl_priority` | Default priority copied into manually created jobs. |
| `allowed_path_prefix` | Optional relative path boundary for the future fetch layer. |
| `robots_policy` | `unknown`, `allowed`, `blocked`, or `manual_review`; only `allowed` is claimable. |
| `source_concurrency_limit` | Per-source active lease limit, default one. |

Timeout, cooldown, crawl health and last/next crawl fields are deferred until a fetch/scheduler policy needs them. Source crawl configuration is operational metadata and does not create a scholarship version.

## Tables and lifecycle

- `crawler_workers`: stable UUID, label, protocol/software versions and enabled/disabled state. Online, idle, busy and stale are derived from heartbeat and active attempts.
- `crawler_worker_activation_codes`: SHA-256 activation-code hash, issuer, expiry and single-use state.
- `crawler_worker_credentials`: SHA-256 bearer-token hash, scopes, expiry, revocation and rotation ancestry.
- `crawl_jobs`: source, idempotency key, due time, state, assignment, current-attempt pointer, attempt count and fencing generation.
- `crawl_attempts`: immutable history rows with worker, attempt/generation, claim replay key, lease-token hash and future response metadata slots.
- `crawler_events`: append-only lifecycle/audit records. Application model hooks reject update/delete. Event detail must never contain bearer or activation secrets.

Job states are `queued`, `leased`, `processing`, `retry_pending`, `completed`, `blocked`, `manual_review`, `failed`, and `cancelled`. Phase 5A transitions are queued/retry-pending → leased → processing; expired lease → retry-pending or failed at the attempt limit; admin cancel is limited to queued/retry-pending/blocked/manual-review; admin retry is limited to failed/blocked/manual-review. Completion/result states are schema-ready but no result endpoint exists.

## Worker authentication and API

An administrator issues a 256-bit random, single-use code. Only its SHA-256 hash is stored; it expires after 15 minutes. Activation consumes it in a locked transaction and returns a separate 256-bit bearer token once. Only that token's SHA-256 hash is stored. Tokens are scoped to `workers.me`, `jobs.claim`, and `jobs.heartbeat`; they expire after one year by default. Rotation revokes the prior credential and returns a new token once. Disabling a worker revokes all credentials. Invalid/expired credentials return 401; a disabled worker or missing scope returns 403. Activation and worker endpoints are rate limited.

Worker routes:

| Method | Path | Scope | Result |
|---|---|---|---|
| POST | `/api/v1/crawler/workers/activate` | activation code | Exchange code for worker token (one-time response). |
| GET | `/api/v1/crawler/workers/me` | `workers.me` | Derived worker presence. |
| POST | `/api/v1/crawler/jobs/claim` | `jobs.claim` | Claim one eligible job or return `data: null`. |
| POST | `/api/v1/crawler/jobs/{job}/attempts/{attempt}/heartbeat` | `jobs.heartbeat` | Renew only the current worker's valid lease. |

All responses use the existing `{success,message,data}` / `{success:false,message,errors}` envelope. Claims require a `claim_request_key`; replays return the same active attempt and deterministic lease token. A claim returns only the registered source URL and its path prefix; arbitrary URLs are never accepted.

Admin routes are under `/api/v1/admin/crawler/...`, in addition to `admin.access`, and use these least-privilege permissions: `crawler.workers.manage`, `crawler.sources.manage`, `crawler.jobs.view`, and `crawler.jobs.manage`. The worker principal is never a Sanctum user and has no admin, review, verification, publication, catalog or account permissions.

## Claim, source slots and fencing

Claim executes in a database transaction. It locks due queued/retry-pending jobs with MySQL `FOR UPDATE SKIP LOCKED`, then locks and rechecks the source and job. It requires active source status, explicit crawl enablement, `http` method, `robots_policy=allowed`, due availability, attempts remaining and a free per-source lease slot. It atomically assigns worker, increments the attempt number and lease generation, inserts one attempt, sets expiry, and appends `job_claimed`.

The lease token is a deterministic HMAC derived from application key plus job, attempt, generation and worker IDs. Only its SHA-256 hash is persisted. Determinism enables safe claim replay without storing the plaintext token. Heartbeat verifies worker, job, attempt, generation, expiry and token hash before extending the lease and moving leased → processing. Stale generations cannot renew. `crawler:reap-expired-leases` marks the current attempt expired and moves the job to retry-pending or failed; the command can be scheduled by a future server scheduler configuration, which is intentionally not installed in 5A.

## Scheduling and Phase 4 boundary

Manual jobs are permission-gated, idempotent and audited. Future `available_at` jobs are not claimable early. Frequency values are stored, but there is no due-job scheduler loop yet. The database queue remains for Laravel server-side work only; the PC crawler is not a Laravel queue worker.

Phase 4 compatibility boundary is `ObservationProcessor::process(RawObservation $observation, string $runKey, string $parserVersion = 'json-v1')`. It loads the private raw payload through `ObservationIngestionService`, then runs `JsonCandidateExtractor`, normalization, validation, change detection and review-task creation. A future parser-result adapter must preserve the raw observation and route extracted candidates/evidence into the same Phase 4 normalization/validation/change/review path while recording parser name/version and evidence pointers. The current JSON path remains unchanged; the adapter and observation submission are not implemented here.

## Future Windows worker boundary

Windows PHP CLI worker → authenticated HTTPS crawler API → Laravel → MySQL. The worker will store credentials in the Windows credential store, fetch only URLs from current leases, and never access MySQL or edit Laravel files. Fetch, robots, SSRF/path enforcement, artifact upload and observation submission must be completed before enabling real sources.

## Known limitations

- No production cPanel resource verification; complete that deployment gate before production rollout.
- No fetch, robots, DNS/redirect/private-IP SSRF defenses, artifact transfer, completion/error/result endpoint or parser adapter.
- Frequency is configuration only; no scheduler loop creates due jobs.
- Admin API responses expose database records through the standard JSON serializer; future monitoring should use dedicated resources and redaction.
- MySQL 8 is the concurrency target for `SKIP LOCKED`; a production race run remains required in the deployment environment.
