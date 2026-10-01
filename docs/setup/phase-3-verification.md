# Phase 3 Verification Instructions and Results

## Safe MySQL test target

Destructive test/migration operations use only `scholarship_tracker_test` on the MySQL 8.4.11 service at `127.0.0.1:3307`. The ignored `.env` points to the primary `scholarship_tracker`; every test invocation below explicitly overrides the DB connection, database name, and port for the process. Never run `migrate:fresh`, `RefreshDatabase`, or destructive test commands against `scholarship_tracker`.

```powershell
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'scholarship_tracker_test'
$env:DB_PORT = '3307'
$env:APP_ENV = 'testing'
& 'C:\newserver\xampp1\php\php.exe' artisan test
& 'C:\newserver\xampp1\php\php.exe' vendor/bin/pint --test
composer validate --no-check-publish
npm.cmd run build
& 'C:\newserver\xampp1\php\php.exe' artisan migrate:status --database=mysql
& 'C:\newserver\xampp1\php\php.exe' artisan route:list --path=api/v1
```

The current schema already indexes cycle status/deadline, the publication pointer, and effective verification lookup. Phase 3 adds no migration or index.

## Phase 3 coverage

- Phase 2.2 public visibility: only the current pointer with an effective verified decision and active official snapshot source; hidden unpublished, pending, rejected, draft, archived, expired, archived-scholarship, or inactive-source cases.
- Version replacement hides the old version; public data follows the new published snapshot.
- Keyword search across title, description, and university.
- Country, region, university, subject, degree, funding classification, cycle, and inclusive deadline filters, including combinations and null deadline behavior.
- Whitelisted sorting and null deadlines last; page/per-page totals and empty pages.
- Public API and detail privacy allow-list; invalid or unknown query values.
- Server-rendered home/list/detail and university/country/subject/degree pages; canonical metadata and no JavaScript dependency.
- Related public opportunities and bounded eager-query count.
- Phase 1, Phase 2, and Phase 2.2 regression coverage.

## Results

**PASS** — MySQL 8.4.11 at `127.0.0.1:3307`, confirmed database `scholarship_tracker_test`. Full suite: 43 passed, 318 assertions; all six migrations report `Ran`. Pint passed, Composer validation is valid, and Vite 7.3.6 production build passed. Route inventory confirms two public API endpoints (`GET /api/v1/scholarships` and `GET /api/v1/scholarships/{slug}`) and seven public web routes including the homepage and four catalog detail paths. No Phase 3 migration or index was added. The primary `scholarship_tracker` database was not modified. Phase 4 was not started.


