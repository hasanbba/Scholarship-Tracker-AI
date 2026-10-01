# Phase 2.2 Verification Instructions and Results

## Safe database target

The PHPUnit configuration defaults to isolated in-memory SQLite. The Phase 2.2 implementation suite was run on MySQL 8.4.11 at `127.0.0.1:3307` by explicitly setting `DB_CONNECTION=mysql`, `DB_DATABASE=scholarship_tracker_test`, and `DB_PORT=3307` for the test process. `RefreshDatabase` applied migrations only to that dedicated test schema. Never run destructive migration commands against the primary `scholarship_tracker` database. The Phase 2.2 migration is additive; its rollback removes only its own tables, pointer, constraints, and index.

## Checks

```powershell
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'scholarship_tracker_test'
$env:DB_PORT = '3307'
php artisan test
vendor/bin/pint --test
composer validate --no-check-publish
npm.cmd run build
php artisan migrate:status --database=mysql
php artisan route:list --path=api/v1/admin
```

Vue build is included as a regression check although Phase 2.2 changes no frontend files. Test and migration checks above explicitly use `scholarship_tracker_test`; the primary `scholarship_tracker` database was not modified.

## Implemented verification coverage

- Append-only pending, verified, and rejected decision records, with latest ordering `decided_at DESC, id DESC`.
- Admin permissions, student/guest/unauthorized denials, and server-controlled actor/time.
- Verified-only publication, active official-source requirement, draft/archived-cycle denial, and same-cycle database integrity.
- Atomic pointer changes, immutable snapshots, publication event history, unpublishing, version replacement, and cycle independence.
- Existing Phase 1 and Phase 2 regression suite.

## Result

**PASS** â€” MySQL 8.4.11, database `scholarship_tracker_test` on port 3307. `php artisan test`: 34 passed, 187 assertions. All six migrations report `Ran`, including `2026_10_01_000000_create_verification_publication_foundation`. `vendor/bin/pint --test`: passed. `composer validate --no-check-publish`: valid. `npm.cmd run build`: passed (Vite 7.3.6). `php artisan route:list --path=api/v1/admin`: 32 routes, including the four internal verification/publication endpoints. The primary `scholarship_tracker` database was not modified. No Phase 3 search, filters, public discovery routes, or SEO pages were implemented.
