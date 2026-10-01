# Phase 3 — Search and Discovery

## Scope and source of truth

Phase 3 adds public scholarship discovery on the existing Laravel/MySQL stack. `PublicScholarshipQuery` is the shared visibility and filtering layer used by the public API, public web pages, and catalog pages. Search starts from `scholarship_cycles` and follows `published_version_id`; it never loads the full catalog and filters it in PHP.

Every discovery result represents a scholarship **cycle**. A program may therefore appear more than once when multiple cycles are independently published. Result links include the cycle key so the detail page can resolve the same cycle. A detail URL without `?cycle=` selects the eligible published cycle with the soonest non-null deadline, with deterministic cycle ID ordering for ties and missing deadlines.

## Phase 2.2 visibility predicate

Public discovery requires all of the following in the database query:

1. The cycle has a current `published_version_id` that belongs to that cycle (the Phase 2.2 composite foreign key enforces ownership).
2. The selected version's effective verification record is `verified`, ordered by `decided_at DESC, id DESC`.
3. At least one official source in the version snapshot still exists as an active official source for the same scholarship.
4. The current scholarship lifecycle is not archived.
5. The current cycle state is `open` or `closed`. Draft, explicitly expired, and archived cycles are excluded. The published snapshot deadline is also compared with the current date in `config('app.timezone')`; an earlier deadline is expired and omitted even if the mutable cycle status still says `open` or `closed`. A null/missing deadline is unknown, not expired, and remains eligible if the other conditions pass. This follows Phase 0's default rule that expired cycles remain historical, not active search results.

The query never uses `scholarships.publication_status` as the public visibility condition. It reads scholarship, university, country, region, subject, cycle, funding, and eligibility values from the currently published immutable version snapshot. This prevents later mutable working values from appearing beside an older published version. Search results do not expose verification records, reviewer identities, internal notes, publication events, or raw source records.

An absent or pending/rejected effective decision does not qualify. Replaced versions cease to appear as soon as the cycle pointer moves. Unpublishing removes the cycle from discovery by clearing that pointer.

## Search and filters

All supported filters are combined on the published version snapshot:

| Parameter | Meaning |
|---|---|
| `q` | Case-insensitive substring in snapshot scholarship title, description, or university name |
| `country` | Active country slug from the snapshot university country |
| `region` | Active region slug from the snapshot university country |
| `university` | Active university slug from the published snapshot |
| `subject` | Active subject slug tagged on the scholarship snapshot or used by a structured eligibility rule |
| `degree` | Active degree slug represented by a structured eligibility rule |
| `deadline_from`, `deadline_to` | Inclusive published snapshot deadline bounds |
| `funding_classification` | Existing cycle funding classification; `unknown` remains distinct from all monetary amounts |
| `cycle` | Cycle key |
| `sort` | Whitelisted order: `deadline_asc`, `deadline_desc`, `newest`, `title_asc`, `title_desc` |
| `page` | One-based page number |
| `per_page` | Page size from 1 through 50; default 12 |

Date bounds may be supplied independently or together. Cycles with no deadline do not match either date bound. Deadline sorting puts null/missing deadlines last in both directions. Default `newest` sorts by published version creation time and then cycle ID. Title sorting is case-insensitive with cycle ID as a stable tie-breaker. Invalid filters, dates, page sizes, and sort values return validation errors; unknown query parameters are rejected.

Eligibility filtering is limited to the existing normalized subject and degree references. No new eligibility schema or inference is introduced.

## APIs and public routes

Public JSON endpoints:

```text
GET /api/v1/scholarships
GET /api/v1/scholarships/{slug}[?cycle={cycle_key}]
```

The list returns the standard success envelope with `data.items` and `data.pagination`. Detail returns the published snapshot and a small related list. API Resources explicitly allow-list public values; internal metadata is never serialized from raw Eloquent models.

Server-rendered public routes:

```text
/
/scholarships
/scholarships/{slug}[?cycle={cycle_key}]
/universities/{slug}
/countries/{slug}
/subjects/{slug}
/degrees/{slug}
```

The catalog pages use the same public query and paginate results. The listing uses a native GET form, links, and server pagination; discovery content remains useful without JavaScript. Vue's public shell adds a browse link as progressive navigation only.

Related opportunities use a shared published subject where one exists, or the same country as a fallback. They are not profile recommendations or match scores.

## SEO behavior

Public pages render titles, descriptions, self/canonical URLs, and index/follow metadata. Filtered search pages use `noindex,follow`; unfiltered search, catalog, and detail pages are indexable. Authenticated SPA shell routes use `noindex,nofollow`. Pagination links preserve validated filters. Sitemap generation is not included.

## Query and index review

Phase 2 already has indexes for cycle status, cycle deadline, `(scholarship_id, status)`, the Phase 2.2 `published_version_id` pointer, version ownership, and effective verification lookup `(version_id, decided_at, id)`. Phase 3 adds no speculative indexes or migration. The main filters and keyword matching operate on immutable JSON snapshots, so a conventional B-tree index would not accelerate those predicates without adding generated columns or database-specific functional indexes; no measured dataset or query plan justified either change. A FULLTEXT index was not added because the current scale and search relevance requirements are not established. Keyword matching currently uses MySQL `LIKE` over published snapshot fields and may require later measurement as the catalog grows.

The list query eager-loads the current published version and its latest decision for API Resources and Blade views. Feature coverage asserts query count remains bounded as result count grows.

## Known limits

- Search is MySQL-specific because it uses JSON extraction/containment over published snapshots; MySQL 8 is the supported runtime.
- Keyword search covers title, description, and university name. It is substring search, not ranked or full-text relevance.
- Results are cycle-level opportunities, so a program with multiple eligible cycles can appear more than once.
- Detail defaults to the nearest non-null deadline; a card's cycle key can select a particular published cycle.
- Filter values are catalog slugs; the basic server-rendered form accepts slugs directly instead of loading unbounded catalog dropdowns.
- Advanced eligibility comparison, crawler data, change detection, matching, recommendations, external search services, sitemap generation, and Phase 4 are out of scope.
