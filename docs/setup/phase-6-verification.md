# Phase 6 verification

## Isolated MySQL test setup

Set the test process to the dedicated `scholarship_tracker_test` database. Leave the primary `scholarship_tracker` database and `.env` unchanged.

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'scholarship_tracker_test'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3307'
php artisan test --compact tests/Feature/Phase6HtmlExtractionTest.php
php artisan test --compact tests/Feature/ScholarshipDataQualityTest.php
php artisan test --compact tests/Feature/CrawlerFoundationTest.php
php artisan test --compact tests/Feature/Phase5CLocalIntegrationTest.php
php artisan test --compact tests/Feature/CrawlerDueSourceTest.php tests/Feature/CrawlerConcurrentDispatchTest.php
```

All HTML and PDF inputs are local fixtures under `tests/Fixtures/phase6`. The local Phase 5C fixture integration uses loopback-only test servers. No public scholarship website or PDF is contacted.

The Phase 6 feature tests cover HTML title/description/date/application extraction, funding and eligibility tables, exact amount/currency/period and maximum wording, GPA/language/nationality normalization, unknown-versus-zero, uncertain/impossible/contradictory values, JSON-LD conflict handling, field provenance, parser/run history, changed content, Phase 4 review handoff, no automatic verification/publication, and explicit unsupported-PDF state.

## Full regression and static checks

After focused tests, run the complete Laravel suite with the same database environment, the standalone worker suite from `crawler-worker`, PHP lint for application and worker files, Composer validation for root and worker, and `git diff --check`. Use `scholarship_tracker_test` for all database tests. Do not run a destructive migration against `scholarship_tracker`.

## Phase boundary

An invalid processing run cannot enter duplicate/change detection. A successful run only creates Phase 4 proposals/review tasks. Human approval, Phase 2.2 verification, and explicit Phase 2.2 publication remain separate gates. PDF extraction, OCR, AI providers, and public-facing changes are outside Phase 6.
