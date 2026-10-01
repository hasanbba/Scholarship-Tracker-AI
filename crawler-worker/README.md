# Scholarship Tracker Crawler Worker (Phase 5B.1-5B.2)

This is an independent Windows-first PHP CLI application. It talks only to Laravel's authenticated HTTPS crawler API. It has no Laravel dependency, no MySQL driver, no `.env`, and no database credentials.

**Phase 5B.2 fetches and stores bounded raw artifacts. It does not extract scholarship data.** The worker fetches only within the registered source origin and allowed path prefix returned with the claimed job. It applies DNS/IP checks, manual redirects, robots rules, MIME and byte caps, and writes complete SHA-256 hashed artifacts to local ignored spool storage. It has no result/completion endpoint, so jobs remain leased until server expiry/reaping; Phase 5C owns result and observation handoff.

## Requirements and install

- Windows PowerShell and PHP 8.3+ CLI.
- PHP extensions: cURL, JSON, OpenSSL, and mbstring.
- Windows Credential Manager for normal Windows use.

From PowerShell:

```powershell
Set-Location 'C:\newserver\xampp1\htdocs\scholarship tracker\crawler-worker'
composer install
php bin/worker --help
composer test
```

No Laravel framework or other runtime package is installed. `composer.lock` is intentionally dependency-free. PHP checks its version before loading the worker.

## Configuration and activation

Set `WORKER_API_BASE_URL` or copy `config/local.example.php` to the gitignored `config/local.php` and edit the local values. The base URL is the Laravel origin (for example `https://tracker.example`); the client appends `/api/v1/...`. HTTPS is required. Plain HTTP is accepted only for localhost/loopback when both development mode and `allow_insecure_local_api` are explicitly enabled.

Provision a short-lived activation code through the Phase 5A admin API, then run:

```powershell
php bin/worker activate
```

If no API URL is configured, activation asks for it. The activation code is entered through PowerShell `Read-Host -AsSecureString`; it is not echoed. The worker sends `{activation_code, protocol_version: 1, software_version}` to `POST /api/v1/crawler/workers/activate`. It stores the returned token and worker identity, and prints only the worker label/UUID. Activation is not retried because the server code is single-use and Phase 5A has no activation idempotency contract.

## Credential storage

On Windows, the `windows` adapter uses the OS Credential Manager generic credential APIs (`CredWrite`, `CredRead`, `CredDelete`). The credential reference defaults to `ScholarshipTracker.CrawlerWorker`. The token is not written into the identity file, source, process arguments, or logs.

For disposable local development only, set `credential_store` to `file-dev` in `config/local.php` (and keep `environment` as `development`). This adapter writes a **plaintext** local credential file under ignored `storage/`; it is explicitly not production-safe. Do not copy it to production. `config/local.php`, `storage/`, logs, spool entries and test state are gitignored.

The Windows Credential Manager round-trip test may be skipped in restricted shells that have no interactive Windows logon session (Windows error 1312). Run activation from a normal logged-in Windows PowerShell session. If Credential Manager is unavailable, use the development adapter only for disposable local tests.

## Commands

```powershell
php bin/worker status
php bin/worker doctor
php bin/worker once
php bin/worker poll
php bin/worker poll --max-cycles=10
```

- `status` calls `/workers/me` and prints worker identity, status, API URL and credential expiry; it never prints the token.
- `doctor` checks PHP/config, HTTPS policy, credential presence, writable logs/spool, public Laravel health, and worker authentication.
- `once` claims at most one job, safely fetches it, and writes a bounded artifact or a structured failure record. It exits successfully when no job is available; a fetch failure returns a worker error. It does not complete the server job.
- `poll` sleeps when no job exists. It executes one safe fetch for a claimed job, heartbeats during longer transfers, then waits for the lease expiry before claiming again. `--max-cycles` bounds local runs.

Windows PHP typically has no PCNTL signal extension. The loop checks for signals only when PCNTL is available; otherwise Ctrl+C/console close behavior is owned by PHP/Windows and cannot guarantee an orderly signal callback. The cURL transfer is bounded by request and operation timeouts; an abrupt process stop may leave an incomplete ignored spool entry, which must never be treated as a finalized artifact. The server lease/fencing reaper remains the job recovery boundary.

## API, retry and logs

The exact Phase 5A wire contract is captured in [`../docs/architecture/phase-5b1-phase5a-contract.md`](../docs/architecture/phase-5b1-phase5a-contract.md). The client sends `Accept: application/json`, JSON content type where needed, `Authorization: Bearer ...` for worker routes, and a generated `X-Request-ID` on each logical request. It validates the Phase 5A response envelope and expected fields.

API and fetch retries are separately bounded. Fetch uses up to two retries for transient DNS/connection/timeouts, 408, 429, and selected 5xx; `Retry-After` is honored up to the configured delay cap. Partial bytes are discarded instead of appended to a retry. Authentication, authorization, unsafe URLs/redirects, robots denial, unsupported MIME, oversized bodies, and permanent 4xx are not retried. Credentials, query strings and response bodies are excluded from logs. Logs are JSON lines under `storage/logs/worker.log`.

Exit codes: `0` success/no job; `2` configuration or PHP version; `3` authentication/authorization; `4` protocol/API/state error; `5` exhausted transient API/transport failure; `6` local storage; `7` unexpected internal failure.

## Local spool and later work

The spool is outside source files under ignored `storage/spool/`; it stores raw bytes as `payload.bin` with a metadata manifest and SHA-256 result. Default maximum is 4 MiB. No artifact upload or observation submission occurs.

See [`../docs/architecture/phase-5b2-safe-fetch.md`](../docs/architecture/phase-5b2-safe-fetch.md) for the fetch security model and [`../docs/setup/phase-5b2-verification.md`](../docs/setup/phase-5b2-verification.md) for offline tests and Windows verification.
# Phase 5C result handoff

After a successful Phase 5B.2 fetch, the worker uploads the finalized spool payload to `POST /api/v1/crawler/jobs/{job}/attempts/{attempt}/artifacts`, then submits the result to the matching `/results` endpoint. Both requests use the authenticated worker bearer credential and stable per-attempt idempotency keys. The artifact request body is raw bytes; metadata is sent in the `X-Crawler-Metadata` base64url header. The server independently checks the lease generation, content type, byte count, SHA-256, URL scope, and 4 MiB cap.

The worker removes a local success artifact only after it receives the result receipt. Bounded API retries reuse the same keys. If retries are exhausted, the finalized spool remains for operator recovery; automatic replay after process restart is not yet implemented. Fetch failures submit an auditable failure result and do not create an observation.

Laravel stores accepted evidence on its private local disk, creates/reuses the Phase 4 observation, and invokes the existing Phase 4 processor. The parser remains JSON-oriented. PDF is preserved with an `unsupported_parser` run code; HTML remains evidence and follows the existing JSON parser contract. Worker software has no database connection and cannot mutate canonical scholarship, verification, or publication data.

**Phase 5C does not verify or publish scholarships.** See [Phase 5C architecture](../docs/architecture/phase-5c-observation-handoff.md) for the server protocol and lifecycle.
