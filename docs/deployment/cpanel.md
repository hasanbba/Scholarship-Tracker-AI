# cPanel deployment assumptions

This phase prepares the application for cPanel without assuming Docker or a VPS. Confirm the specific host's PHP 8.3+ version/extensions, MySQL 8 compatibility, HTTPS certificate, document-root controls, writable storage/cache paths, Composer availability, Node build availability, and cron/queue process policy before a production release.

## Document root

Keep the Laravel project (including `.env`, `vendor`, `storage`, and source) outside the public web root where the account layout permits. Point the site's document root to this project's `public/` directory. If cPanel requires `public_html`, copy only the contents of Laravel `public/` there and update `index.php` and `maintenance.php` to point to the private project bootstrap paths. Never expose `.env`, `vendor`, `storage`, database dumps, or the project root through HTTP. Verify a request for `/.env` is not served before launch.

## Build and release

Build assets in a compatible build environment with Node/npm (`npm ci`, then `npm run build`) and deploy the generated `public/build` assets and manifest. Production does not need a Vite dev server. Configure Composer dependencies for production, a strong unique `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, database credentials from the environment, trusted HTTPS URL, secure session cookies, exact Sanctum domains, and exact credentialed CORS origins. Set ownership/permissions so only required `storage/` and `bootstrap/cache/` paths are writable by PHP.

Run migrations deliberately during release with a backup and a rollback/recovery plan. Never run `migrate:fresh` in production. Keep database credentials least-privileged and out of the browser and source control.

## Queue and scheduler

See [queue and scheduler setup](../setup/queue-scheduler.md). Use a persistent queue worker only if the host supports a managed long-running process. Otherwise use the documented database queue/cron fallback and verify it on the target host. Add one cPanel cron entry for Laravel's scheduler. No scholarship/crawler schedules are part of this phase.

## Security release gate

Production administrator access requires MFA, even though MFA is intentionally outside Phase 1. Do not grant or expose production admin accounts until MFA and the agreed operational controls are implemented. Configure HTTPS, secure cookies, rate limits, monitored logs, backups, and a tested restore path before production use.
