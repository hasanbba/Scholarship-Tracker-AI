# ScholarSignal foundation

Foundation and Phase 2 scholarship core for the Fully Funded Scholarship Intelligence & Discovery Platform. This repository contains Laravel 12, Sanctum first-party authentication, the role/permission foundation, versioned API conventions, a Vue 3 application shell, normalized catalogs, cycle-owned scholarship funding/eligibility, and immutable cycle snapshots. Crawler, AI, discovery/search, payment, matching, and notification products remain out of scope.

Read [the Phase 0 architecture](docs/architecture/phase-0-foundation.md) and [Phase 0.1 patch](docs/architecture/phase-0.1-architecture-patch.md) before changing boundaries. Local setup is in [docs/setup/local.md](docs/setup/local.md); API contracts are in [docs/api/conventions.md](docs/api/conventions.md); deployment assumptions are in [docs/deployment/cpanel.md](docs/deployment/cpanel.md).

Phase 2 ownership, API, and migration notes are in [docs/architecture/phase-2-scholarship-core.md](docs/architecture/phase-2-scholarship-core.md).

Quick commands after environment setup:

```powershell
php artisan migrate --seed
npm.cmd run dev
php artisan serve --host=localhost --port=8000
```

Verification: `php artisan test` and `npm.cmd run build`.
