# Local setup

## Requirements

- PHP 8.3+ with `ctype`, `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite` (tests), `tokenizer`, `xml`, and `zip` enabled.
- Composer 2, Node.js/npm, and MySQL 8+ (for the application database).
- This checkout has been provisioned with Laravel 12.69, Sanctum 4.3, Vue 3, Vue Router, Pinia, Axios, Vite, and the Vue Vite plugin. The versions are recorded in `composer.lock` and `package-lock.json`.

## Install and configure

From the project root:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

Create a local MySQL database and a least-privilege application user, then set `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. Use a dedicated test configuration for automated tests; PHPUnit uses an in-memory SQLite database and does not need production credentials.

Set `APP_URL=http://localhost:8000` and include the browser host in both `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS`. Keep the app and API same-origin where possible. For HTTPS staging/production, use the actual trusted host names, `SESSION_SECURE_COOKIE=true`, and keep `SESSION_HTTP_ONLY=true`; do not use wildcard credentialed CORS origins.

```powershell
composer install
npm.cmd ci
php artisan migrate --seed
npm.cmd run dev
```

In a second terminal run `php artisan serve --host=localhost --port=8000`. Open `http://localhost:8000`. The SPA uses the compiled Vite assets in production; in development Laravel detects the Vite server. The web server, Vue shell, and `/api/v1` API share the same origin by default.

## Environment variables

`APP_*` identifies the runtime and encryption key. `DB_*` configures MySQL. `SESSION_*`, `SANCTUM_STATEFUL_DOMAINS`, and `CORS_ALLOWED_ORIGINS` configure first-party browser sessions and cross-origin policy. `VITE_API_BASE_URL` is empty for same-origin requests; set an HTTPS origin only for an intentionally separate frontend host. `QUEUE_CONNECTION=database` and `CACHE_STORE=database` provide local seams using the included Laravel migrations. `MAIL_*` uses Laravel's log mailer by default. Never commit `.env`, keys, database credentials, session values, or tokens.

Do not use `APP_DEBUG=true` outside local development. Use separate `.env` values and database credentials for local, staging, and production.

## Authentication setup

The SPA uses Sanctum's first-party cookie/session mode, not browser bearer tokens. Before registration or login, the shared Axios client obtains `/sanctum/csrf-cookie`; it sends credentials and the XSRF header for `/api/v1` calls. The production frontend and Laravel API should be same-site over HTTPS. Production administrator access must remain disabled until MFA is implemented as required by the Phase 0.1 architecture.

After migrations and seeding, public registration creates a `student` role assignment. Administrative roles are provisioned by a trusted operator; do not expose self-service role elevation or seed a production administrator password.

## Database setup

Phase 1 migrations create Laravel user/session/password reset/cache/queue/Sanctum support and the role/permission foundation only. They do not create scholarship-domain tables. `DatabaseSeeder` installs the `student`, `admin`, and `super_admin` roles plus the `admin.access` permission. It can be safely re-run.

For tests:

```powershell
php artisan test
```

The PHP test suite uses SQLite `:memory:` as configured in `phpunit.xml`. To verify MySQL connectivity and migrations locally, set local MySQL variables in `.env`, then run `php artisan migrate:status` and `php artisan migrate:fresh --seed` only against the local development database.

## Useful commands

```powershell
php artisan route:list --path=api/v1
php artisan migrate:status
php artisan queue:work --once
php artisan schedule:list
npm.cmd run build
php artisan test
```
