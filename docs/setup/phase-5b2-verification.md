# Phase 5B.2 Verification

## Offline worker tests

From PowerShell:

```powershell
Set-Location 'C:\newserver\xampp1\htdocs\scholarship tracker\crawler-worker'
composer validate --no-check-publish
composer install
php -l bin/worker
composer test
```

Tests use deterministic mocked DNS and fake HTTP; they do not contact public DNS or external websites. They exercise source scope, unsafe IP answers, mapped IPv6, path traversal, redirects, robots, complete artifact hash/metadata, partial spool cleanup, prior API retry behavior and CLI setup. The system Windows Credential Manager test may skip when the shell lacks an interactive logon session.

## Laravel contract tests

Run only against the dedicated test database:

```powershell
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'scholarship_tracker_test'
$env:DB_PORT = '3307'
$env:APP_ENV = 'testing'
php artisan test --filter='CrawlerFoundationTest|CrawlerConcurrentClaimTest'
```

The Phase 5A claim response adds registered source identity/URL, robots policy, and concurrency limit. No migration or primary database operation is part of Phase 5B.2.

## Local controlled HTTP E2E

The default IP policy blocks loopback and private destinations, including local test servers. Do not weaken that production policy for an E2E shortcut. Use the fake HTTP client/mocked resolver tests for deterministic fetch cases. Any separate local-server harness must use a test-only explicit resolver/IP-policy injection and remain isolated from production configuration.

## Recovery and boundaries

Artifacts are in ignored `crawler-worker/storage/spool/<job>-<attempt>/payload.bin`. Failures delete partial payloads and emit a structured failure code. Since no result/completion endpoint exists, a successful fetch still awaits lease expiry/reaper recovery. Phase 5C owns upload, server result receipt, and observation handoff. No HTML/PDF parsing, scholarship extraction, AI or real-site traffic occurs here.
