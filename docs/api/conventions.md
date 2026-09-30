# API conventions

All application API routes use `/api/v1`. Public Laravel browser pages are separate web routes. `/scholarships` is not an API endpoint; no scholarship endpoints exist in Phase 1. The `/api/v1/health` endpoint is an infrastructure liveness check and returns no database or secret details.

## Response envelopes

```json
{"success":true,"message":"Operation successful.","data":{}}
```

Validation errors return HTTP 422:

```json
{"success":false,"message":"Validation failed.","errors":{"email":["The email field is required."]}}
```

Authentication failures return 401, authorization failures return 403, not-found returns 404, and throttling returns 429. Error output does not include stack traces, SQL details, passwords, tokens, environment values, or secrets. List responses introduced in later phases must paginate and use API Resources. Controllers validate through Form Requests and return the standard envelope via the shared support helper.

## Phase 1 endpoints

| Method | Path | Access | Purpose |
|---|---|---|---|
| GET | `/api/v1/health` | Public | Safe application liveness |
| POST | `/api/v1/auth/register` | Public, throttled | Create account and sign in as student |
| POST | `/api/v1/auth/login` | Public, throttled | Start first-party session |
| POST | `/api/v1/auth/logout` | Authenticated | Invalidate session and rotate CSRF token |
| GET | `/api/v1/auth/me` | Authenticated | Current account, role names and permissions |
| GET | `/api/v1/users/{user}` | Authenticated, owner policy | Verify user-owned resource boundary |
| GET | `/api/v1/admin/foundation` | Authenticated with `admin.access` | Foundation authorization check for admin shell |

Sanctum uses same-site cookies and CSRF protection for this first-party SPA. No crawler token APIs or scholarship endpoints are implemented in this phase. Guest calls to protected routes receive 401. Students receive 403 on the admin foundation route. Administrative permission checks are server-side; Vue route guards only improve navigation.
