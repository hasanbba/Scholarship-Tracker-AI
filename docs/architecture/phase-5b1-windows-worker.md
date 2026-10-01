# Phase 5B.1: Windows Worker Communication Foundation

## Architecture and boundary

`crawler-worker/` is a separate Composer project and PHP 8.3+ CLI. It uses the Laravel API over HTTPS and has no Laravel bootstrap, Artisan dependency, MySQL extension, SQL credentials, frontend/UI, or external-site fetcher.

**Phase 5B.1 does not crawl external websites.** A job URL is treated as response data only. `once` claims at most one job, creates a local metadata spool entry, heartbeats once and exits without marking the job complete. `poll` waits when empty; after a claim it heartbeats once and does not claim again until the local lease expiry time. This avoids holding a no-op job indefinitely or accumulating leases. Server reaping is the recovery path.

## Phase 5A contract used

The client uses the inspected real implementation, detailed in [`phase-5b1-phase5a-contract.md`](phase-5b1-phase5a-contract.md): activation sends activation code/protocol/software version and gets a one-time token; worker status calls `/workers/me`; claim sends `claim_request_key`; heartbeat sends `lease_token` to the job/attempt endpoint. It follows the Laravel `{success,message,data}` envelope and 401/403/409/422/429 status model. There is no completion endpoint or server request-ID middleware. The worker sends `X-Request-ID` for its own trace correlation.

Activation is not retried: the code is single-use, and the current server contract has no idempotent activation replay. If the response is lost after server consumption, provision a new code and resolve/revoke the activated worker credential administratively.

## Components

- Typed `WorkerConfig` validates protocol/timeout/retry/size settings and requires HTTPS except explicitly allowed loopback HTTP in development.
- `CrawlerApiClient` is separate from cURL transport; it validates envelopes and endpoint schemas, assigns request IDs, classifies API errors and applies bounded retries.
- `CredentialStoreInterface` has a Windows Credential Manager implementation plus an explicit plaintext `file-dev` adapter restricted to development mode.
- `WorkerIdentityStore` stores UUID, label, API URL and expiry, not tokens.
- `StructuredLogger` writes JSON lines with redaction of token/authorization/activation/secret fields and known secret values.
- `LocalSpoolInterface` creates job/attempt entries with metadata and bounded payload operations; Phase 5B.1 does not download or upload content.
- CLI commands: `activate`, `status`, `doctor`, `once`, `poll`, and `--help`.

## Security and retries

The bearer token is never printed or logged. Activation uses a hidden PowerShell secure prompt on Windows. The native Credential Manager adapter pipes operation data through standard input and keeps secrets out of process command arguments. The fallback file adapter is plaintext and documented as disposable development-only storage. HTTP is allowed only for localhost/loopback with both explicit development settings; cURL certificate verification remains enabled and redirects are not followed.

Retries are limited to transport timeouts/connection failures, 408, 429 and 5xx, with an attempt cap, exponential backoff, jitter, and bounded `Retry-After`. 400/401/403/404/409/422 and malformed/schema-invalid responses are not retried. API response bodies and authorization headers are never logged.

## Shutdown, tests and limitations

The polling loop checks PCNTL signal flags only if that extension exists. Typical Windows PHP CLI does not provide PCNTL, so Ctrl+C/console close cannot promise an orderly handler. This phase has no fetcher; if the process stops, its lease naturally expires and Laravel fencing/reaping recovers it.

The worker uses a dependency-free PHP test harness and fake HTTP transport; tests do not contact Laravel or university websites. The native Credential Manager test requires an interactive Windows logon session and may skip with Windows error 1312 in a restricted execution shell. A separate end-to-end local Laravel verification is recorded in the setup report.

Deferred: real HTTP fetching, robots.txt, SSRF/DNS/IP checks, redirects, MIME/content validation, download size limits, source/global rate limiting, artifact capture/upload, crawl completion, observation submission and HTML/PDF parsing. These belong to Phase 5B.2 and later.
