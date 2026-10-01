# Phase 5B.1 Verification

## Worker tests

From PowerShell:

```powershell
Set-Location 'C:\newserver\xampp1\htdocs\scholarship tracker\crawler-worker'
composer validate --no-check-publish
composer install
php bin/worker --help
composer test
```

The worker test harness uses a fake HTTP transport and local temporary folders. It tests Phase 5A request/response construction, status/claim/heartbeat, 401/403/409/422 no-retry behavior, 429/5xx/transport retry limits, Retry-After, schema failures, request IDs, credential/log redaction, HTTPS policy, local spool bounds, no job, once-mode heartbeat, and the lack of Laravel/MySQL dependencies. It never connects to external websites.

The Windows Credential Manager round-trip is attempted only on Windows; an isolated shell without a Windows logon session can return error 1312, which is reported as a skip. Test in a normal logged-in Windows PowerShell session before relying on that adapter. The plaintext `file-dev` adapter is for disposable local development only.

## Local Laravel communication check

Use a Laravel server pointed at the dedicated `scholarship_tracker_test` database, not the primary database. For example, in a separate PowerShell session set process-only environment values before `php artisan serve`:

```powershell
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'scholarship_tracker_test'
$env:DB_PORT = '3307'
php artisan serve --host=127.0.0.1 --port=8128
```

Provision an activation code through the authenticated Phase 5A admin endpoint, then configure the worker explicitly for local HTTP and the development credential adapter:

```powershell
Copy-Item config\local.example.php config\local.php
# In config\local.php set credential_store to file-dev for this disposable test only.
php bin/worker activate --api-url=http://127.0.0.1:8128
php bin/worker doctor
php bin/worker status
php bin/worker once
php bin/worker poll --max-cycles=1
```

Do not use the worker's `file-dev` credential adapter for a production credential. For a normal Windows session use the Windows Credential Manager adapter and HTTPS. To run Laravel backend tests, explicitly set `DB_CONNECTION=mysql`, `DB_DATABASE=scholarship_tracker_test`, and `DB_PORT=3307` in the test process; do not edit `.env` or target `scholarship_tracker`.

## Expected behavior and recovery

- Activation returns success without printing the token; the identity file contains UUID/label/API URL/expiry only.
- Status and doctor call the Laravel API using the stored bearer.
- `once` with an empty queue exits 0; with a job it sends one heartbeat and does not fetch or complete the job.
- `poll` sleeps rather than busy-loops; it holds at most one communication-only lease before waiting for expiry.
- No browser UI, MySQL access, real source URL request, artifact upload or observation submission occurs.

Inspect `crawler-worker/storage/logs/worker.log` for JSON events. Do not paste credential files or logs containing local host/account details into issue trackers. Spool/log/state files are ignored by Git.
