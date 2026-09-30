# Fully Funded Scholarship Intelligence & Discovery Platform

## Phase 0 Architecture and Implementation Plan

**Status:** Architecture baseline for review  
**Scope:** Phase 0 only. No application code, migrations, crawler, or Phase 1 setup is included.

## 1. Architecture decisions

1. Laravel 12 is the system of record and owns business rules, persistence, authorization, queues, scheduling, and the versioned REST API.
2. MySQL 8 is the production relational store. The PC crawler communicates only with authenticated HTTPS APIs; it never receives database credentials or connects to MySQL.
3. Use a modular Laravel monolith initially. Keep module boundaries explicit and move to separate services only when measured scale or operational needs justify it.
4. Vue 3, TypeScript, Vue Router, Pinia, and Axios power the authenticated student and administration experiences. Public discovery pages are server-rendered by Laravel Blade for crawlability, with Vue used for progressive interactive components. This avoids making public SEO depend on client rendering.
5. Scholarship identity and application periods are distinct: a stable scholarship/program may have many cycles. Public pages resolve the current cycle while retaining appropriate historical cycle information.
6. Crawl observations are immutable evidence. They enter a staging/review workflow and cannot update verified or published scholarship fields without an explicit processing and verification action.
7. Every published scholarship must have an official source, official application URL where available, verification status, and verification timestamp. A third-party source is secondary evidence, never a replacement for an available authoritative source.
8. Model money, dates, taxonomies, and eligibility as typed/relational data where practical. Use JSON only for genuinely variable extractor payloads or extensible rule parameters, with validation and versioning.
9. Subscription entitlements are configured on the server and enforced by policies/services and API middleware. UI visibility is not access control.
10. AI may propose extracted or normalized values. Deterministic checks and human review remain authoritative for verification and publication.

## 2. System architecture

```text
Guest browser
  ├─ Laravel-rendered public pages (SEO, canonical URLs, metadata)
  └─ Vue interactive search/filter components ───────────┐
                                                        │ HTTPS
Student/admin browser                                   │
  └─ Vue SPA ─ Sanctum session/API ─────────────────────┤
                                                        ▼
                                                Laravel 12 application
                                  ┌─────────────────────┼──────────────────┐
                                  │                     │                  │
                            Domain modules        Queue/scheduler     Notifications
                                  │                     │                  │
                                  └─────────────────────┼──────────────────┘
                                                        ▼
                                              MySQL 8 system of record

Registered official websites → owner-operated crawler → HTTPS crawler API
                                                → raw observations/object storage
                                                → parsing, validation, deduplication
                                                → review and verification
                                                → approved canonical scholarship/cycle
                                                → publication and search
```

The Laravel app is a modular monolith with API and server-rendered web entry points sharing the same domain services and authorization rules. Production cPanel deployment must confirm PHP extensions, document-root arrangement, persistent queue support, cron availability, TLS, storage permissions, and supported MySQL version before Phase 1 selects concrete hosting settings. A queue adapter may begin with Laravel's database queue on constrained hosting; Redis can be introduced for a VPS/cloud deployment without changing domain contracts.

### Deployment boundaries

- **Web:** Laravel app, public Blade routes, `/api/v1`, Vue assets, authentication and admin UI.
- **Database:** MySQL accessible only to Laravel and controlled operational tooling.
- **Queue/scheduler:** Laravel queue workers and scheduler invoked using the host's supported process/cron facilities.
- **Crawler:** Separate owner-operated process, independently versioned and configured; outbound HTTPS only to approved source sites and the Laravel crawler API.
- **Files/evidence:** Store raw HTML/response artifacts outside public web root, with access controls and retention limits. Database records keep hashes, metadata, and protected object references. The exact storage backend is a deployment decision.

## 3. Module map

| Module | Responsibilities | Primary boundaries |
|---|---|---|
| Identity & Access | Login, Sanctum, account lifecycle, roles, permissions, admin access | Policies/Gates; session and CSRF protection for first-party SPA |
| Student Profile | Academic profile, preferences, consent | Private user-owned data; validated profile update services |
| Scholarship Catalog | Stable programs, cycles, structured funding and eligibility | Canonical verified records; cycle-scoped deadlines and URLs |
| Taxonomy & Geography | Universities, official organizations, countries, regions, subjects, degree levels | Stable slugs and controlled vocabularies |
| Source Registry | Official source URLs, crawl method/frequency, robots/access state, health | Allow-listed registered sources, source-level rate limits |
| Ingestion & Evidence | Worker registration/heartbeat, crawl jobs, raw observations, artifacts | Separate crawler credentials and staging tables |
| Data Quality | Normalization, validation, duplicate candidates, change detection | No implicit writes to verified/published fields |
| Review & Verification | Review queue, evidence comparison, approval/rejection, publication | Authorized human decision with audit/version record |
| Discovery & SEO | Search, filters, public pages, canonical metadata, sitemap | Paginated results; active cycle defaults; indexable public routes |
| Personalization | Match explanations and profile-to-requirements comparison | Advisory score; never a guarantee; entitlement checks server-side |
| Student Workspace | Saved records, notes, application tracker | User-owned records and authorization |
| Subscription & Entitlements | Plans, subscription state, feature/limit configuration | Entitlement service; provider integration deferred to later phase |
| Notifications | Event dispatch, preferences, templates, delivery log | Queue-based, preference/consent-aware, channel adapters |
| Administration & Audit | Dashboards, source/job health, settings, activity history | Permission-checked actions and immutable audit trail |

Repository pattern is not a default requirement. Use Eloquent models and focused query/service classes; add repository abstractions only where they isolate a real persistence or domain boundary.

## 4. Frontend architecture

- **Public website:** Laravel Blade routes for `/`, `/scholarships`, `/scholarships/{slug}`, `/universities/{slug}`, `/countries/{slug}`, `/subjects/{slug}`, and `/degrees/{slug}`. Include canonical links, metadata, structured page content, pagination links, and sitemap support. Interactive filters may hydrate as Vue components and use paginated APIs.
- **Student SPA:** Vue 3 Composition API + TypeScript, Vue Router, Pinia, and Axios. Routes include `/dashboard`, `/profile`, `/saved`, `/applications`, `/notifications`, and `/subscription`. Keep server state/API access in composables or feature services; components render state and user actions.
- **Admin SPA:** Separate route/layout and permission-aware navigation for `/admin` and catalog, review, source, crawler, user, subscription, and reporting tools. Backend authorization is mandatory on every protected operation.
- **Shared client:** One API client handles CSRF bootstrap, credentials, error envelope parsing, and request IDs. Feature stores do not duplicate authorization rules or embed plan limits.
- **UX states:** Every data view supports loading, empty, error, and paginated states. Never fetch unbounded scholarship collections.

## 5. Backend architecture

Suggested organization by feature/domain (final file layout is a Phase 1 decision):

```text
HTTP route/controller → Form Request → policy/entitlement check
                      → application service/use case
                      → domain model/query → MySQL
                      → API Resource / standard response envelope
```

Controllers coordinate transport only. Form Requests validate input. Policies/Gates enforce actor permissions. Services own workflow transitions and cross-entity business rules. API Resources define output shape. Jobs perform slow/retryable tasks. Events describe committed domain changes; listeners handle indexing, notifications, and audit side effects where appropriate.

All public APIs are versioned under `/api/v1`. Paginate list endpoints, eager-load intentionally, validate sort/filter allow-lists, and return consistent JSON. Example:

```json
{"success":true,"message":"Scholarship retrieved successfully.","data":{},"meta":{}}
```

Validation failures use HTTP 422 and `{"success":false,"message":"Validation failed.","errors":{}}`. Authorization, not-found, rate-limit, and server failures use appropriate HTTP status codes; production responses never expose stack traces, SQL, credentials, or raw exception details.

## 6. Database ERD description

```text
users 1──1 student_profiles
users *──* roles (via user_roles) *──* permissions (via role_permissions)
users 1──* saved_scholarships *──1 scholarships
users 1──* applications *──1 scholarship_cycles
users 1──* subscriptions *──1 plans; plans 1──* plan_entitlements

universities 1──* scholarships
scholarships 1──* scholarship_cycles
scholarships *──* subjects (scholarship_subjects)
scholarships 1──* funding_packages; scholarship_cycles 1──* eligibility_requirements
scholarship_cycles 1──* scholarship_sources *──1 sources
scholarship_cycles 1──* scholarship_versions

sources 1──* crawl_jobs 1──* crawl_attempts
crawler_workers 1──* worker_heartbeats
crawl_attempts 1──* raw_observations 1──* processing_runs
processing_runs 1──* duplicate_candidates; processing_runs 1──* proposed_changes
proposed_changes *──1 scholarship_cycle (nullable until matched)
review_tasks 1──* review_decisions; approved decisions create versions/publication changes

users 1──* notifications; users 1──* audit_logs (actor, nullable for system actions)
```

Country/region/continent are normalized location entities; a university belongs to a country and may optionally reference a region. A source can be associated with a university or an official scholarship organization. Cycle-specific source links preserve evidence for the particular application period.

## 7. Database table specification

Names below are logical names and can be adapted to Laravel conventions. Common fields (`id`, timestamps, and where appropriate `deleted_at`) are omitted from individual rows. Add foreign keys and explicit delete behavior. Prefer restrict/cascade rules that preserve audit and evidence history; never cascade-delete crawl evidence or audit history with a catalog record.

| Table | Key columns and purpose |
|---|---|
| `users` | `name`, normalized unique `email`, `password`, `email_verified_at`, `status`, `last_login_at` |
| `roles`, `permissions`, `role_user`, `permission_role` | Named role/permission catalog and pivots; unique pair constraints |
| `student_profiles` | Unique `user_id`; country/nationality IDs; degree; GPA/scale or percentage; language/test scores; graduation year; work experience; profile visibility/consent fields |
| `profile_preferences` | `user_id`, preference type and taxonomy/location ID; unique user/type/value |
| `countries`, `regions` | Stable unique slug/code, name, parent country for regions |
| `universities` | Country/region IDs, name, unique stable slug, canonical website, status |
| `subjects`, `degree_levels` | Unique stable slug/code, label, active status; subjects can optionally have parent subject |
| `scholarships` | Stable program identity: university/organization, unique slug, canonical title, summary/description, lifecycle status, visibility/publication timestamp |
| `scholarship_cycles` | `scholarship_id`, cycle label/year, opening/deadline dates and timezone policy, application URL, status, verification status/time, published time; unique scholarship + cycle key |
| `scholarship_subject` | Scholarship-to-subject pivot; unique pair |
| `funding_packages` | Cycle ID, funding classification, tuition coverage/amount/currency, stipend amount/currency/period, accommodation, insurance, travel, visa, research grant, application fee, other benefits and notes, verification metadata |
| `eligibility_requirements` | Cycle ID, requirement type, operator/value/unit, optional taxonomy/country references, source/evidence reference, required flag; extensible typed rows rather than one opaque blob |
| `sources` | Optional university/organization owner, URL, type/name, crawl method/frequency, JS flag, robots/access status, enabled state, health status, checked/success/failure timestamps, failure count, HTTP status, response-time aggregate, notes |
| `scholarship_cycle_source` | Cycle/source association, official/primary designation, application/source URLs, last verified timestamp |
| `crawler_workers` | Unique worker ID, hostname label, version, status, last job and heartbeat, jobs processed; no secrets stored in plaintext |
| `worker_tokens` | Worker ID, hashed/revocable credential fingerprint, scopes, expiry/rotation/revocation metadata; token secret is only shown at provisioning and never logged |
| `crawl_jobs` | Source ID, requested/scheduled time, lifecycle state, attempt count, idempotency key, claimed worker ID, claimed/lease-expiry timestamps, terminal reason; claim is atomic and completion replay-safe |
| `crawl_attempts` | Job ID, worker ID, start/end, result state, HTTP status, response duration, safe error category/message, retry time |
| `raw_observations` | Attempt/source IDs, fetched URL, content hash, response metadata, protected artifact reference, observed time; immutable |
| `processing_runs` | Observation ID, parser/rule versions, state, structured output, validation result, completion/error metadata |
| `duplicate_candidates` | Processing run, candidate scholarship/cycle, signal scores/reasons, classification/state, reviewer outcome |
| `proposed_changes` | Processing run, target record nullable until matched, field path, old/new normalized values, evidence pointer, confidence, state |
| `review_tasks`, `review_decisions` | Task type/priority/assignee/state and decision actor/outcome/reason/evidence references; records retain history |
| `scholarship_versions` | Scholarship/cycle, monotonically increasing version, actor/system origin, change type, complete reconstructable cycle snapshot or field diff, source/review decision; immutable |
| `field_provenance` | Scholarship/cycle/version, field path, source ID/URL, raw observation ID, observed timestamp, extraction/parser method, review state; optional confidence is not verification |
| `saved_scholarships` | User, scholarship, saved time, notes; unique user + scholarship |
| `applications` | User, scholarship cycle, status, deadline snapshot, notes, application URL snapshot, timestamps; unique user + cycle if product policy permits only one tracker item |
| `plans`, `plan_entitlements` | Plan code/name/active state; feature key, typed/configured value and limit, effective dates |
| `subscriptions` | User, plan, provider reference, status, period start/end, cancellation metadata; unique provider references |
| `notification_preferences`, `notifications`, `notification_deliveries` | User/channel/event preferences; queued notice and per-channel delivery outcome/retries |
| `audit_logs` | Actor (nullable system), action, entity type/id, old/new values with sensitive-field redaction, IP, user agent, request/correlation ID, timestamp |
| `settings` | Allow-listed operational settings with type, value, description and updated-by; secrets stay in environment/secret storage |

### Money, dates, and flexible fields

- Money uses fixed precision decimal columns plus ISO currency and an explicit period/unit; never binary floating-point arithmetic.
- Dates are stored consistently (UTC instants for timestamps; date columns for calendar deadlines) and displayed in an explicitly selected timezone. A deadline's interpretation/timezone must be captured where the official source provides it.
- Eligibility uses typed rows/operators for common requirements. A versioned JSON payload may hold uncommon parser-specific details but cannot silently override canonical typed values.
- `old_value`/`new_value` snapshots are JSON only where the changed field is inherently structured; redact private data and cap payload size.

## 8. Index and integrity strategy

- Unique indexes: normalized user email; public slugs; country code; role/permission names; cycle key within scholarship; pivot pairs; saved user + scholarship; worker ID; idempotency keys; provider references.
- Foreign keys on all relational links. Use database constraints for uniqueness and required ownership, plus application validation for richer rules.
- Discovery indexes: published/status + deadline; scholarship cycle status + deadline; university/country; taxonomy pivots in both traversal directions; verification timestamp. Confirm actual composite order against Phase 3 query shapes using `EXPLAIN`.
- Ingestion indexes: source + scheduled time + state for queue claiming; job + attempt sequence; observation hash/source/time; processing state/time; review queue state + priority + created time.
- User workspace indexes: user + saved timestamp; user + application status/deadline; user + notification read/created timestamp.
- Audit/version indexes: entity type + entity ID + timestamp; scholarship/cycle + version number.
- Avoid indexing every field. Validate MySQL index length/collation choices for Unicode slugs and URLs; URLs are not unique indexed as long unbounded text. Use normalized URL hashes where uniqueness is needed.
- Retain database backups and verify restores operationally before production. Raw artifact retention and database backup retention are separately configured.

## 9. API module map

All paths are under `/api/v1`; exact verbs and schemas are settled in the corresponding implementation phase.

| API group | Representative endpoints | Access |
|---|---|---|
| Auth | `auth/csrf-cookie`, `auth/register`, `auth/login`, `auth/logout`, `auth/me` | Public throttled / authenticated |
| Scholarships & search | `scholarships`, `scholarships/{slug}`, `search`, taxonomy/location reads | Public, paginated; only published verified content |
| Student profile | `profile` read/update | Authenticated owner |
| Saved | `saved`, `saved/{scholarship}` | Authenticated; entitlement limits enforced server-side |
| Applications | `applications`, `applications/{id}` | Authenticated owner |
| Notifications | `notifications`, read state, preferences | Authenticated owner |
| Subscription | `subscription`, available plans/features | Authenticated; provider mutations later phase |
| Admin catalog | scholarships/cycles, universities, taxonomies, publish/unpublish | Permission-gated |
| Admin review | pending tasks, duplicate candidates, proposed changes, decisions | Review-specific permissions; all decisions audited |
| Admin sources/crawler | sources, jobs, errors, worker status, retry/enable/disable/frequency | Permission-gated and audited |
| Admin operations | users, plans, settings, reports, audit | Least-privilege permissions |
| Crawler worker | `crawler/heartbeat`, `crawler/jobs/claim`, `crawler/jobs/{id}/result`, observations upload | Separate scoped worker token, HTTPS, strict validation, rate limits |

Public list endpoints paginate and accept allow-listed filters/sorts. Crawler submission endpoints enforce body and artifact size limits, source/job ownership, idempotency, bounded retries, and per-worker/source quotas. API resources never return internal evidence storage paths, private profile fields, worker tokens, or operational secrets.

## 10. Authentication, roles, and permissions

For the first-party Vue app, use Laravel Sanctum cookie-based sessions, CSRF protection, secure/HTTP-only/SameSite cookies, HTTPS, and same-site deployment where possible. Public API consumers do not receive a browser session by default. Crawler workers use separately scoped, revocable, hashed bearer tokens over HTTPS with rotation, throttling, request validation, and auditable attribution. Never put crawler credentials in the browser or crawler logs.

| Role | Public discovery | Student workspace | Review/catalog | Sources/crawler | Users/billing/settings/audit |
|---|---|---|---|---|---|
| Guest | Read published records within configured guest entitlements | None | None | None | None |
| Student | Read discovery | Own profile, saved items, applications, preferences | None | None | Own subscription view |
| Premium Student | Read discovery and premium features by entitlement | Same, plus configured premium capabilities | None | None | Own subscription view |
| Admin | Read discovery | No access to private profile data by default | Assigned catalog/review permissions | Assigned operational permissions | Explicit assigned permissions only |
| Super Admin | Read discovery | Break-glass access only when explicitly audited | All admin permissions | All operations | Manage users, plans, settings, roles and audits |
| Crawler Worker | None | None | Submit observations only | Claim assigned work and heartbeat | None |

Roles are bundles of permissions, not hard-coded frontend identities. Enforce policy checks on every read/write of private or administrative resources. Admins receive least privilege; sensitive role changes and emergency access are logged. Rate-limit login, registration, public search, and worker APIs independently.

## 11. Scholarship lifecycle and verification workflow

Canonical scholarship lifecycle: `discovered → extracted → processing → pending_review → verified → published → updated`, with terminal/side states `expired`, `archived`, and `rejected`. A cycle has its own opening/deadline and active/expired state; expiry of one cycle does not expire the long-term program if another cycle is active.

```text
Registered official source
  → crawl job and immutable raw observation
  → deterministic parsing / optional AI suggestion
  → schema, source, date, funding and eligibility validation
  → duplicate candidate and field-level change proposal
  → human review against captured official evidence
  → approve/reject/request-more-evidence
  → approved values create immutable version and audit entry
  → publish cycle only after required official-source checks
  → schedule deadline status and monitoring
```

New or changed crawler values remain proposals until approved. A blocked source records failure and creates a review task; the crawler does not bypass access controls. A verified change creates a version with actor/system origin, time, change type, old/new values, source, and decision reference. Deadline states (`open`, `closing_soon`, `upcoming`, `expired`, `archived`) are derived from cycle dates, configured threshold, and timezone policy rather than duplicated as manually maintained facts. Public active discovery excludes expired cycles by default while preserving history.

Verification is field/evidence aware: reviewer sees canonical value, proposed value, official URL, capture timestamp/hash, and prior history. Funding classification requires reviewing the structured benefits; AI confidence alone cannot assert `fully_funded`.

## 12. Crawler lifecycle and control plane

```text
pending → queued → processing → extracted → validated → completed
                                      └→ manual_review
Failure paths: failed → retry_pending → queued; blocked → review task
```

Laravel owns source registry, schedule, job assignment, lease expiry, retries, and monitoring. Workers heartbeat on a configured interval with worker ID/version/status and counters. The server marks a worker stale/offline when the heartbeat exceeds a configurable multiple of the interval. Jobs are idempotent, leased to one worker at a time, and re-queued after expired leases. Retry policy uses bounded attempts and backoff; permanent errors and access blocks do not retry blindly. Per-source concurrency/rate limits and robots/access status are checked before assignment. The admin control center reports queue/job counts, source health, blocked sources, review backlog, heartbeat freshness, and error categories.

Worker result APIs accept observations and normalized suggestions, never direct canonical scholarship updates. Worker start/pause/stop controls are server-side assignment controls; a worker must respect pause/stop on its next poll and finish or safely release its current lease. Keep worker deployment independent so later PC, VPS, and cloud workers use the same protocol.

## 13. Security and operational controls

- Validate every request with Form Requests and allow-listed fields; use Eloquent bindings, output escaping, CSRF protections, secure password hashing, and least-privilege database credentials.
- Enforce authorization and subscription entitlements in Laravel services/policies, with tests planned in later implementation phases.
- Separate student PII from public scholarship data; redact sensitive fields from logs and audit snapshots.
- Use TLS, secure session cookies, rate limits, worker credential scopes/rotation/revocation, upload size limits, and private evidence storage.
- Do not bypass CAPTCHA, authentication, explicit blocks, robots directives, or other access restrictions. Record a blocked outcome and route it for human review.
- Log structured event IDs, actor, entity, source/job, and correlation ID; avoid tokens, passwords, full private profile payloads, and unbounded page bodies in logs.
- Production plan includes encrypted database and application/configuration backups, off-host copies, retention, and a documented restore procedure. Verify backups by restore drills.
- Use environment/secret management for keys and deployment-specific values. Keep local, staging, and production configuration distinct; do not commit `.env` secrets.

## 14. Development dependency map

```text
Phase 0 architecture (this document)
  → Phase 1 Laravel/Vue/MySQL foundation, auth, API conventions, UI shells, CI/config
  → Phase 2 catalog schema, cycles, structured funding/eligibility, audit/version base
  → Phase 3 public SEO pages, search, taxonomies, pagination
  → Phase 4 source evidence, review, verification, duplicates, change history
  → Phase 5 worker API, registry, queues, crawler jobs/health
  → Phase 6 assisted extraction and normalization with deterministic validation
  → Phase 7 configurable plans, entitlements, payment provider integration
  → Phase 8 profile matching, saved scholarships, application tracker
  → Phase 9 notification preferences, events, delivery channels
  → Phase 10 SEO refinement, indexing, growth analytics
  → Phase 11 production hardening, restore drills, performance/security review
```

Key dependencies: verification needs source evidence and version history; crawler ingestion needs source registry and worker auth; AI extraction needs stable raw observations and schemas; matching needs structured profile and eligibility; premium matching/limits need entitlement checks; deadline and update alerts need cycle dates, change events, preferences, and notification delivery.

## 15. Phase 1 implementation plan

1. Confirm hosting constraints: PHP 8.3+, Laravel 12 compatibility, MySQL 8 availability, document root, HTTPS, cron, persistent queue options, and storage permissions.
2. Create the Laravel 12 application and Vue 3/TypeScript build foundation; configure local/staging/production environment boundaries and secret handling.
3. Establish modular namespaces, route groups (`web`, `/api/v1`, admin, crawler reserved), API response/error conventions, Form Request, Resource, policy, and service patterns.
4. Set up MySQL connection, migration conventions, queue/cache/session drivers supported by target host, scheduler entry, and structured logging/correlation IDs.
5. Implement Sanctum first-party cookie authentication, CSRF flow, user model, initial role/permission foundation, rate limits, and admin route protection.
6. Build Blade public shell and Vue student/admin shells, router/store/API client, responsive navigation, and loading/error/empty states without implementing scholarship business features.
7. Add deployment/run documentation and a verified local smoke path; defer catalog, crawler, payments, matching, and notifications to their planned phases.

Phase 1 acceptance requires repeatable automated verification gates for clean installation/configuration, migrations and database connectivity, health endpoint, authentication/logout, protected API, 401/403/authorized access boundaries, validation envelope and HTTP status, Vue production build/type check (if TypeScript), route/API-client/auth state, loading/error/empty/permission-denied UI states, and deployment/asset/storage/queue/scheduler/HTTPS assumptions. See [Phase 0.1 patch](phase-0.1-architecture-patch.md) for the frozen detailed gate and scope.

## 16. Open decisions for implementation

These do not block the architecture baseline, but should be resolved when their dependent phase starts: production host capabilities and database version; initial crawler implementation language and HTML artifact storage; public guest quotas; closing-soon threshold; source artifact retention; subscription/payment provider and jurisdiction; student data retention/consent details; search engine choice if MySQL full-text search proves insufficient. Defaults must be configurable and documented rather than embedded in frontend code.

## Completion boundary

This document supplies the requested architecture, ERD, table plan, module map, API map, role/permission matrix, scholarship and crawler workflows, dependency map, and Phase 1 plan. Phase 0 ends here. Phase 1 is not started.
