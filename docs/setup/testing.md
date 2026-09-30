# Testing and verification

Run the suite using `php artisan test`. PHPUnit defaults to a fresh in-memory SQLite database, runs the Laravel migrations, and seeds baseline roles/permissions within relevant tests. The suite covers foundation auth/API behavior plus Phase 2 catalog, scholarship/cycle, funding, eligibility, source, version/history, and authorization behavior. Run the suite against MySQL 8 as well for supported database validation.

Build the frontend using `npm run build`. The project uses JavaScript, so a TypeScript type-check is not configured. For an installation smoke check use `composer install`, `npm ci`, `php artisan migrate --seed`, and the documented local MySQL connection. CI or a later phase can enforce these commands as merge gates.

Before production, perform a separate clean install, validate MySQL 8 migrations, inspect the public document root and HTTPS cookies, verify storage permissions, run auth flows in a browser against configured Sanctum domains, confirm queue/scheduler behavior on the actual cPanel host, and test backup restore. A passing SQLite suite does not establish MySQL or cPanel runtime compatibility.

## MySQL workstation note — 2026-09-29

XAMPP MariaDB remains on port 3306; a separate MySQL Community Server 8.4.11 service (`MySQL84`) listens on `127.0.0.1:3307`. The ignored local `.env` points to the primary `scholarship_tracker` schema. Before Phase 2 migration tests using `RefreshDatabase`, select a dedicated MySQL test schema such as `scholarship_tracker_test`; do not use destructive migration commands against the primary schema. See [Phase 2 data model](../architecture/phase-2-scholarship-core.md) for ownership/history rules.
