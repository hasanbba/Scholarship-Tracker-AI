# Phase 2 — Scholarship Core Data Model

This document records the Phase 2 implementation under the approved Phase 0 and Phase 0.1 architecture. It does not start discovery, verification workflow, crawler, AI, matching, subscriptions, or notifications.

## Ownership decision

The final approved foreign-key decision is:

```text
scholarship_funding.cycle_id                 -> scholarship_cycles.id
scholarship_eligibility_rules.cycle_id       -> scholarship_cycles.id
scholarship_versions.scholarship_id          -> scholarships.id
scholarship_versions.cycle_id                -> scholarship_cycles.id
```

Funding and eligibility rows do **not** have `version_id`. Funding is one structured current record per cycle; eligibility is a set of typed current rules per cycle. Every admin mutation that changes scholarship/cycle/source/funding/eligibility state creates a new immutable version snapshot for each affected cycle. Each snapshot includes the stable scholarship details, cycle details, subjects, sources, funding and eligibility state at that point. Version numbers increase within each cycle. Database constraints enforce that a version's `scholarship_id` is the scholarship that owns its `cycle_id`; historical snapshots and parent records are protected by restrictive foreign keys. Version model updates/deletes are rejected and no mutation API is provided for versions.

The mutable cycle-owned rows are the current working state. Earlier approved/admin-recorded values remain reconstructable from immutable cycle snapshots. Phase 2 does not implement a review or approval workflow; snapshots record admin changes and are not themselves a claim that a scholarship is verified or publishable.

## Catalog entities

`regions`, `countries`, `universities`, `subjects`, and `degrees` are reusable normalized entities with stable unique slugs, explicit active/inactive status, indexes, and relational constraints. Country ISO2/ISO3 codes are unique when supplied. Universities belong to a country. Scholarships belong to a university and may link to reusable subjects.

## Scholarship core

- A scholarship is a stable program identity; its application periods are separate `scholarship_cycles` with a unique `(scholarship_id, cycle_key)`.
- `scholarship_subject` prevents duplicate subject associations at the database level.
- `scholarship_sources` stores official URL records and source type, with canonicalized URLs and a SHA-256 URL uniqueness key per scholarship. This is a source registry only; it has no fetch/crawl behavior.
- Funding is stored in `scholarship_funding`, one row per cycle. Each benefit has nullable `DECIMAL(15,2)` amount, currency, and period fields. Null means unknown/not stated; explicit zero remains `0.00` with its stated currency/unit.
- Eligibility uses queryable typed rows in `scholarship_eligibility_rules`, with a validated rule type, operator, JSON scalar/list normalized value, unit, optional taxonomy references, and human-readable text.
- `scholarship_versions` contains immutable JSON cycle snapshots and actor/source metadata. Its unique key is `(cycle_id, version_number)`.

Delete behavior is conservative: university/country/source/rule/cycle relationships restrict destructive parent deletion; regions can be detached from countries; scholarship-subject links can be detached. No scholarship or version delete endpoint exists. Admins archive/deactivate using status fields.

## API and authorization

All application JSON endpoints remain under `/api/v1`. Public catalog GET routes return active, paginated catalog values. Catalog writes and all scholarship management routes require Sanctum authentication plus the server-side `admin.access` permission and Form Request authorization. API Resources define output; success and validation errors retain the foundation envelope. Scholarship CRUD is an admin foundation only; there is no public scholarship discovery endpoint in this phase.

Admin endpoint families:

```text
GET /api/v1/catalog/{regions,countries,universities,subjects,degrees}
POST/PATCH /api/v1/admin/catalog/{...}
GET/POST /api/v1/admin/scholarships
GET/PATCH /api/v1/admin/scholarships/{scholarship}
GET/POST /api/v1/admin/scholarships/{scholarship}/cycles
GET/PATCH /api/v1/admin/cycles/{cycle}
GET/POST /api/v1/admin/scholarships/{scholarship}/sources
GET/PUT /api/v1/admin/cycles/{cycle}/funding
GET/POST /api/v1/admin/cycles/{cycle}/eligibility
PATCH/DELETE /api/v1/admin/eligibility/{rule}
GET /api/v1/admin/cycles/{cycle}/versions
```

Catalog and admin mutations use explicit allow-lists and reject students/guests server-side. Version endpoints are read-only. The public API exposes catalog data only; no draft/unverified scholarship is exposed publicly.

## Verification

Use a dedicated MySQL 8+ test schema for destructive migration checks. Do not run `migrate:fresh` against the primary `scholarship_tracker` schema. `ScholarshipCoreApiTest` covers cycle identity/history, ownership columns, funding decimal/unknown-vs-zero behavior, eligibility validation, sources, taxonomy constraints, API envelopes, and guest/student/admin boundaries.

Synthetic sample records are isolated in `DevelopmentScholarshipSeeder` and labeled as examples. Load them only in a development database with `php artisan db:seed --class=DevelopmentScholarshipSeeder`; do not use this seeder for production data.

```powershell
php artisan migrate:status
php artisan test
npm.cmd run build
```

The source tree contains no crawler, crawl worker/jobs/observations/tokens/endpoints, AI extraction, duplicate/change engine, review automation, matching, application tracker, subscriptions, payments, notification workflows, or advanced search.
