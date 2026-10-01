# ScholarSignal foundation

ScholarSignal is a Laravel 12 scholarship discovery platform with Sanctum authentication, a role/permission foundation, a Vue 3 account shell, normalized catalogs, cycle-owned scholarship funding/eligibility, immutable cycle snapshots, append-only verification/publication decisions, and public search/discovery.

Read [the Phase 0 architecture](docs/architecture/phase-0-foundation.md) and [Phase 0.1 patch](docs/architecture/phase-0.1-architecture-patch.md) before changing boundaries. Local setup is in [docs/setup/local.md](docs/setup/local.md); API contracts are in [docs/api/conventions.md](docs/api/conventions.md); deployment assumptions are in [docs/deployment/cpanel.md](docs/deployment/cpanel.md).

Phase 2 ownership, API, and migration notes are in [docs/architecture/phase-2-scholarship-core.md](docs/architecture/phase-2-scholarship-core.md).

Phase 2.2 verification/publication rules are in [docs/architecture/phase-2.2-verification-publication.md](docs/architecture/phase-2.2-verification-publication.md). Phase 3 public discovery rules are in [docs/architecture/phase-3-search-discovery.md](docs/architecture/phase-3-search-discovery.md).

Quick commands after environment setup:

```powershell
php artisan migrate --seed
npm.cmd run dev
php artisan serve --host=localhost --port=8000
```

Public discovery is server-rendered at `/`, `/scholarships`, `/scholarships/{slug}`, `/universities/{slug}`, `/countries/{slug}`, `/subjects/{slug}`, and `/degrees/{slug}`. The corresponding JSON API is under `/api/v1/scholarships`.

Verification: `php artisan test` and `npm.cmd run build`; see [docs/setup/phase-3-verification.md](docs/setup/phase-3-verification.md) for the dedicated MySQL test database commands.
