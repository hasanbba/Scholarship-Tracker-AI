# Fully Funded Scholarship Intelligence & Discovery Platform

## Phase 0.1 — Architecture Patch and Phase 1 Freeze

**Status:** Approved architecture refinements before Phase 1  
**Date:** 2026-09-29  
**Authority:** Read together with [Phase 0 foundation](phase-0-foundation.md). This patch supplements, and does not replace, that baseline. If implementation exposes a conflict, stop and resolve it explicitly before proceeding.

This patch settles seven implementation-critical decisions: cycle/version ownership of funding and eligibility; separate public web and API route namespaces; atomic crawler job claims with leases; field-level provenance; immutable historical versions; automated Phase 1 verification gates; and mandatory MFA for production administrator access. It introduces no scholarship, crawler, AI, payment, matching, or notification product implementation.

## 1. Data ownership and history

`scholarships` represents the stable, long-lived program identity: title, university, stable description/category, slug, and long-term relationships. `scholarship_cycles` represents an application period: label, opening/deadline, application URL, cycle-specific availability and application information.

Funding and eligibility facts belong to the applicable cycle and are captured in that cycle's immutable published version snapshot. A new cycle or approved change creates new facts/version rows; it never rewrites historical values. The data model must answer both “what were the requirements in 2026?” and “what changed for 2027?”

### Funding

Funding is cycle/version scoped, with structured fields for tuition, stipend/living allowance, accommodation, health insurance, travel, visa support, research grant, application fee, other benefits, currency/period, classification, notes, evidence, and verification state. Unknown and explicit zero are distinct: unknown means evidence is absent or inconclusive; zero means an authoritative source explicitly establishes no amount/benefit. Amounts use fixed precision decimals with currency and unit/period, never floating point.

### Eligibility

Eligibility rules are cycle/version scoped and queryable as typed requirements, including nationality, degree, field, GPA and scale, percentage, IELTS/TOEFL/PTE/Duolingo, GRE/GMAT, age, work experience, graduation year, academic background, and other requirements. Changes enter review/version history. Example: 2026 GPA ≥3.0 and IELTS ≥6.5 remain separately queryable from 2027 GPA ≥3.2 and IELTS ≥7.0.

### Immutable version model

Each approved publication/change creates an immutable version tied to both the scholarship and its cycle, with monotonically increasing version number, actor/system origin, timestamp, change type, old/new values or complete reconstructable snapshot, source/evidence references, and review decision. Historical cycles and versions are retained. Crawl observations are evidence and proposals only; they do not overwrite canonical or published values.

## 2. Separate web and API interfaces

Public browser routes are Laravel-rendered, useful without JavaScript, paginated, canonicalized, SEO-described, and expose published records only:

```text
/
/scholarships
/scholarships/{slug}
/universities/{slug}
/countries/{slug}
/subjects/{slug}
/degrees/{slug}
```

Application APIs are distinct, versioned routes under `/api/v1/...`, for example `/api/v1/scholarships`, `/api/v1/search`, `/api/v1/profile`, `/api/v1/saved-scholarships`, and `/api/v1/applications`. The browser route `/scholarships` and API `/api/v1/scholarships` are separate interfaces, not interchangeable routes. Crawler operations use `/api/v1/crawler/...`, with worker authentication and authorization separate from browser session authentication.

## 3. Crawler claim, lease, and completion contract

Multiple workers may operate concurrently. Claiming is a transaction-safe atomic operation: while worker A holds a valid lease, worker B cannot receive the same active job. A claim records worker ID, claim time, lease expiry, attempt number, idempotency key, and state. Expired leases become eligible for retry subject to maximum attempts, backoff, source health, and failure category. Blocked sources are not blindly retried or bypassed.

Completion is replay-safe. Repeated completion requests must not duplicate observations, publish records, double-increment counters, or corrupt state. The exact Laravel/MySQL locking/claim query is a Phase 5 implementation detail; this concurrency behavior is an architectural contract. Worker results submit observations and suggestions only.

## 4. Field-level provenance

For every material canonical field, the system can identify where its current verified value came from. Provenance records associate a scholarship/cycle/version and field path with source ID and URL, raw observation ID, observed timestamp, extraction method/parser version, and verification/review state. Confidence may be stored for assisted extraction but is not verification: an AI score such as 0.97 never sets `verified=true`. Verification is an explicit authorized workflow decision linked to evidence.

For example, deadline may cite observation 1001 from the official university page, funding may cite observation 1007 from an official PDF, and IELTS may cite observation 1010 from a graduate-school page. This field-level evidence supplements the general source relationship.

## 5. Phase 1 automated verification gates

Phase 1 completion requires recorded automated verification evidence; a successful build alone is insufficient. Include gates for:

- Clean installation, configuration loading, migrations, database connectivity, and health endpoint.
- Authentication, logout, protected endpoint behavior, validation response/status, and authorization boundaries: guest receives 401 on a protected endpoint; student receives 403 on an admin endpoint; an authorized admin succeeds on its permitted endpoint.
- Success and validation API response envelopes with correct HTTP status codes.
- Vue production build, route loading, API client setup, authentication-state handling, loading, error, empty, and permission-denied states; TypeScript type checking if TypeScript is selected.
- Asset serving, Laravel public entry point, environment separation, storage permissions, queue and scheduler configuration seams, and HTTPS assumptions.

Automated checks should be repeatable from documented clean setup steps and fail the implementation gate when a required check fails. Database-backed authorization tests must run against the supported test database configuration, not be inferred from a frontend build.

## 6. Production administrator MFA

Production administrator access must require MFA in addition to password/session authentication. Full MFA implementation is not required in Phase 1 and may be delivered during security hardening, but production must not be opened to administrator access without it. MFA does not replace least-privilege permissions or audit logging; privileged access and role changes remain auditable.

## 7. Dependency rules

```text
Identity → roles/permissions → API conventions → catalog
  → scholarships + cycles → cycle/version funding and eligibility → public discovery

Source registry → crawler → immutable observations → normalization
  → field provenance + duplicate/change detection → review → verification → publication
```

Verification depends on source evidence and version history. Crawler ingestion depends on source registry and separate worker authentication. Matching depends on structured profiles and eligibility. Premium limits depend on server-enforced entitlements. Deadline/update notifications depend on cycle dates, approved change events, preferences, and delivery infrastructure.

## 8. Phase 1 scope freeze

**In scope:** Laravel 12 and PHP 8.3+ foundation; MySQL connection; Vue 3, Vue Router, Pinia, shared API client; `/api/v1` conventions; Sanctum first-party authentication; basic user and roles/permissions foundation; policies/Gates; protected API; health endpoint; queue/scheduler seams; logging; environment configuration; deployment documentation; responsive application shell; automated foundation tests.

**Out of scope:** scholarship CRUD/cycles/funding/eligibility; crawler or workers; AI extraction; duplicate/change engines; matching; application tracker; subscriptions/payments; notification workflows; advanced search; production crawler infrastructure.

## 9. Acceptance and freeze

Phase 0.1 is complete when funding and eligibility are cycle/version aware; browser web and API routes are distinct; lease claim/completion behavior is defined; field provenance and historical integrity are defined; Phase 1 automated gates and production administrator MFA are recorded; Phase 1 scope is frozen; and no Phase 1 implementation has started under this patch.

The authoritative baseline is [Phase 0 foundation](phase-0-foundation.md) plus this patch. Phase 1 may begin only under a later explicit phase instruction. Implementation must stop and report any conflict with either document rather than silently changing the architecture.
