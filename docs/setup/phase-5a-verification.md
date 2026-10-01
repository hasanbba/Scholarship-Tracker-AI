# Phase 5A Verification

## Scope

Verifies the server-side crawler foundation only: additive source settings, worker activation/credentials, worker-only auth, job creation/claim/heartbeat, expiry detection, least-privilege admin routes and append-only events. It does not contact external scholarship sites.

## Isolated database

Use the dedicated test database `scholarship_tracker_test` on the configured MySQL test port (3307). Do not point migrations/tests at the primary `scholarship_tracker` database. The application `.env` must remain unchanged. Tests can override `DB_CONNECTION=mysql`, `DB_DATABASE=scholarship_tracker_test`, and `DB_PORT=3307` in the test process.

## Checks

1. Run `CrawlerFoundationTest` against the isolated database.
2. Run the full backend suite against the same isolated database.
3. Run `composer validate`.
4. Run Laravel Pint on changed PHP files and inspect `git diff --check`.
5. Confirm no frontend files changed, no live websites were contacted, and primary database configuration/data were not used or modified.
6. Confirm no plaintext activation code or bearer/lease token is stored in the database or written into event details.

The focused feature test covers activation and single use, token hashing/rotation/disable, worker isolation from admin routes, source config and eligibility, future jobs, permission denial, idempotent claim, one attempt, heartbeat fencing/event, and lease expiry handling. Concurrency correctness relies on MySQL transactional row locks and needs a multi-connection MySQL race test before production rollout.

## Later required verification

Before the Windows worker can fetch or submit data, add tests for actual simultaneous MySQL claim races and source slots; credential expiry/revocation; cancellation/retry audit; request rate limits; result-mutation fencing; DNS/redirect/private-address/path SSRF controls; robots policy; bounded artifact upload; and Phase 4 adapter compatibility. Production cPanel PHP/MySQL/cron/storage/upload limits are a separate deployment gate.
