# Phase 4 — Data Quality, Provenance, Duplicate and Change Review

**Status:** Architecture specification and implementation reference; Phase 4 implementation is complete and verified (see `docs/setup/phase-4-verification.md`).  
**Authority:** Read with Phase 0, Phase 0.1, Phase 2, Phase 2.2 and Phase 3. Phase 0.1 supplements Phase 0; the later phase documents settle ownership details.  
**Boundary:** This phase implements the evidence-processing and human-review domain. It does not implement a crawler, public discovery changes, AI provider integration, or automatic publication.

## 1. Scope

Phase 4 accepts immutable source observations, processes and validates them, detects potential identity duplicates and field changes, presents evidence for human review, and applies approved changes as a new immutable cycle version with field-level provenance. Verification uses the existing Phase 2.2 workflow. Publishing remains a separate, explicit Phase 2.2 action.

Included: observation input contract and persistence; processing, normalization and deterministic validation; duplicate candidates; field-level change proposals; review tasks and append-only decisions; canonical working-state update and version creation following approval; provenance; integration with existing version verification.

Excluded: worker registration/leases, source fetching, robots.txt, scraping, AI provider calls, autonomous approval, automated publishing, payment/subscription, matching, notifications, and changes to Phase 3 public routes/query policy. Phase 4 may use synthetic observations and fixtures; production source observations arrive through the same contract.

## 2. Dependency order and Phase 5 boundary

The apparent Phase 4/5 ordering conflict is resolved by separating the observation contract from its producer. Phase 0 assigns raw observations and processing to the staging/review path and Phase 5 to crawler/worker infrastructure. Phase 0.1 explicitly makes crawler results observations and suggestions only, followed by normalization, provenance, duplicate/change detection and review. Phase 4 therefore defines and consumes the observation contract; it does not require the crawler implementation.

```text
Phase 2 source registry + scholarship/cycle/version schema
    → Phase 4 observation contract + ingestion boundary
    → Phase 4 processing → validation → duplicate/change proposals
    → human review → canonical working-state update + immutable version
    → Phase 2.2 verification → explicit Phase 2.2 publication

Phase 5 source registry → crawler/worker → observation contract (above)
```

Answers to the dependency questions:

- Phase 4 does **not** require a real crawler and can be fully implemented/tested with synthetic observations. Production operation requires an observation producer, but the producer is outside Phase 4.
- The observation producer creates observations. Phase 4's `ObservationIngestionService` validates and persists them idempotently; `ObservationProcessor` creates processing runs; normalization/validation components produce candidate payloads; detection services create duplicate candidates and change proposals; `ReviewService` creates review tasks/decisions; an approval application service updates mutable working rows and creates the next immutable version and provenance; the existing `VerificationService` verifies/rejects that version; the existing `PublicationService` publishes/unpublishes it.
- Phase 5 implements the producer and transport/worker mechanics only. It conforms to this input contract and does not need duplicate, review, verification, or publication internals. Phase 5 can consume the contract without changing Phase 4's domain model.

The minimum pre-Phase 4 dependencies are the Phase 2 `scholarship_sources`, `scholarships`, `scholarship_cycles`, `scholarship_funding`, `scholarship_eligibility_rules`, and `scholarship_versions` schema, plus Phase 2.2 `verification_records`, `publication_events`, and `published_version_id`. Existing Phase 2 sources are scholarship-scoped; Phase 4 must not introduce cycle-owned source links contrary to the implemented Phase 2 model.

## 3. Observation input contract

`raw_observations` is the canonical, append-only record of one successfully captured source representation. The raw body is stored outside the relational database under a protected, non-public object reference; the row stores its immutable reference and SHA-256 hash. Do not store arbitrarily large HTML/PDF bodies in MySQL. The object must be retained for at least as long as any processing, proposal, provenance, review, or version history refers to it. Storage encryption/access/retention are deployment controls; a missing artifact is an integrity error, not permission to fabricate evidence.

Minimum fields:

| Field | Rule / reason |
|---|---|
| `id` | Big integer primary key; stable internal identity. |
| `source_id` | Required FK to `scholarship_sources`; the registered source establishes ownership and allowed URL scope. Restrict deletion. |
| `observed_url` | Required URL actually observed; preserve the fetched URL, not just the registry's canonical URL. |
| `observed_url_hash` | Required SHA-256 of the canonical comparison form; permits indexed URL matching without replacing the exact observed URL. |
| `observed_at` | Required UTC timestamp assigned by the trusted ingestion service, not accepted as a client-controlled verification time. |
| `content_hash` | Required 64-character lowercase SHA-256 of exact raw bytes; detects same captured content. |
| `artifact_ref` | Required opaque protected-storage key for the exact raw bytes. |
| `content_type`, `content_length` | Content metadata useful for safe processing and integrity checks; length non-negative. |
| `source_access_status` | Required capture outcome (`success`, `blocked`, `not_found`, `rate_limited`, `error`, `unknown`); non-success captures may be recorded but are not processable as scholarship evidence. |
| `producer_type`, `producer_ref` | Required producer kind and optional external job/attempt reference. Phase 4 fixtures/imports and future Phase 5 worker attempts can be traced without coupling to crawler job schema. |
| `idempotency_key` | Required producer-generated stable key for replay-safe receipt; unique with `source_id`. Retries of one delivery reuse it. |
| `created_at` | Trusted receipt time; distinct from `observed_at`. |

`source_id + idempotency_key` is the receipt identity. It is **not** `URL + hash + timestamp`: timestamp precision and recrawl timing must not make a retried delivery into a second observation. Add non-unique lookup indexes on `(source_id, observed_url_hash, content_hash, observed_at)` and `(source_id, content_hash, observed_at)` for content comparisons. Do not make content hash unique: observing unchanged content on a later capture is still useful observation history. Same `source_id + content_hash` means identical bytes were seen before; the later capture is retained as a separate observation if its idempotency key differs, then detection marks it unchanged/no-op. URL normalization is for comparison only; preserve the exact observed URL. The comparison form lowercases scheme/host, removes default ports/fragments, and retains path/query; it must not strip query parameters that can identify different official content.

Observations are append-only and never edited or deleted through application workflows. Reprocessing creates a new processing run linked to the same observation; it never rewrites the observation or a prior run. The observation contract does not require crawler job/lease tables. Phase 5 may submit a stable job/attempt reference and its own replay key.

## 4. Processing, normalization and validation

```text
RAW OBSERVATION
  → PROCESSING RUN
  → EXTRACTED CANDIDATE DATA
  → NORMALIZATION
  → VALIDATION
  → DUPLICATE / CHANGE DETECTION
```

An extracted value is the parser's claim as found in the source. A normalized value is a deterministic canonical representation (for example ISO date or typed eligibility value). A validated value passes schema, type, range, ownership and consistency checks. A proposed canonical value is a validated value awaiting authorized human decision. A verified canonical value exists only through a verified version decision in Phase 2.2. None of these states imply the next state automatically.

Each `processing_runs` row is one execution for an observation and a fixed parser, normalization and validation ruleset tuple. It records `parser_name`, `parser_version`, `normalization_version`, `validation_version`, `status`, start/finish times, structured extracted and normalized candidate payloads, validation errors/warnings, and a `run_key`. The key is unique per observation and requested pipeline/run identity. Reprocessing intentionally uses a new run key/version tuple; delivery retry of the same request reuses its key. A run's state progresses `queued → running → succeeded | invalid | failed`. Transient execution retry may update the in-flight state/attempt count before terminal completion; terminal payload, errors and timestamps are immutable. A retry after terminal failure creates a new run. Use `attempt_count` and `last_error_code` for operational retries, not a separate historical attempt table in Phase 4; retain detailed safe logs under the app's existing logging policy.

`extracted_payload` and `normalized_payload` are versioned JSON because their parser-defined shape evolves. `validation_errors` and `validation_warnings` are structured JSON with stable error codes and field paths, not free-form-only strings. A run links to exactly one immutable observation. Run output contains the field-level evidence pointer (observation ID plus JSON path/page/anchor where available) for each extracted value; validation must reject pointers outside the observation/artifact. No AI confidence is required. If later used, store it as advisory metadata only.

Processing and detection are asynchronous-capable, but the database contract is independent of queue technology. Only successful valid runs enter change/duplicate detection; invalid/failed runs may produce a diagnostic review task, but never canonical proposals.

## 5. Field-level provenance

`field_provenance` answers “where did this specific value in this specific version come from?” It is version-scoped, cycle-scoped, field-scoped, append-only and immutable. A row has `id`, `scholarship_id`, `cycle_id`, `version_id`, canonical `field_path`, nullable `source_id`, nullable `source_url_snapshot`, nullable `raw_observation_id`, nullable `processing_run_id`, `observed_at`, `extraction_method`, `parser_version`, `review_state`, evidence locator, and timestamps. The composite scholarship/cycle/version ownership must be constrained to the same cycle and scholarship; optional run/observation/source references must be consistent with each other and the version owner. Nullable evidence links allow historical/manual Phase 2 versions and human-entered values to remain representable; new observation-driven values require source and observation evidence.

`review_state` records the provenance's immutable state at version creation (`approved` for approved proposals; `manual_recorded` for pre-existing/manual values). Verification state is **not copied** into provenance: it is derived from the append-only effective `verification_records` decision for `version_id`, ordered by `decided_at DESC, id DESC`. This prevents a stale “verified” flag when a later decision rejects/reopens a version. If an API needs to display verification beside a provenance row, it joins the version's effective decision. **Confidence is not verification.**

For each new version, provenance must cover every material field whose value changed and every material field whose evidence was affirmatively reviewed; unchanged fields can carry forward a reference to their prior provenance rows while preserving history. To ensure a complete answer for the whole snapshot, the version builder should copy forward provenance associations to the new version with the new version ID and original evidence references, marking their review state `carried_forward`; it must not update prior version rows. Version-scoped provenance therefore remains reconstructable and queryable without mutating old records.

## 6. Duplicate detection

Keep the five cases distinct:

| Case | Treatment |
|---|---|
| Same source content repeated | Same bytes (`source_id + content_hash`) indicate repeated content, not a second scholarship. Preserve each distinct capture; detection produces no duplicate-scholarship candidate and no change proposal when normalized values match. |
| Same program from another source | Create a `duplicate_candidate` pairing the incoming discovery/run with an existing scholarship identity for human resolution. |
| Same scholarship, new application cycle | New cycle under the existing scholarship when cycle identity differs; do not call it a duplicate scholarship. A reviewer resolves uncertain cycle association. |
| Same cycle, changed information | Change proposals against the existing cycle; not a duplicate. |
| Similar but distinct programs | Candidate may be rejected by reviewer; title or text similarity alone never merges records. |

Signals are explainable and non-decisive: university identity; exact/normalized official URL; normalized title; degree; subject; cycle key/label/year; structured funding; typed eligibility; and textual similarity of title/description. Strong exact URL/title/university agreement raises candidate priority; disagreement in university or cycle is a reason to inspect, not an automatic rejection. Missing values are unknown, not mismatch. The architecture specifies no numerical score or threshold; matching weights, similarity algorithm and configurable routing bands are implementation policy and must be calibrated/tested without auto-merging. Do not use funding/eligibility similarity as identity proof.

`duplicate_candidates` stores a canonical unordered pair `(processing_run_id, candidate_scholarship_id, candidate_cycle_id nullable)` and a unique key preventing the same run-target pair twice. Candidate direction is not meaningful; `processing_run_id` is the incoming side. Include `classification` (`program_identity`, `cycle_identity`), `state` (`possible`, `confirmed`, `rejected`, `resolved`), JSON signal details with compared values/reasons, optional non-authoritative score, reviewer resolution and timestamps. Do not persist speculative `NEW`/`PROBABLE` states as workflow states: a run with no candidate is simply no candidate; possible/confirmed/rejected/resolved are supported by proposal/review lifecycle. Confirmed duplicates are not merged automatically; resolution links the incoming program/cycle to the selected canonical identity through an approved review decision. Keep candidate and decision history; candidate evidence is immutable, while its workflow state may transition with append-only resolution records.

## 7. Change detection and `proposed_changes`

**Unambiguous baseline:** compare a processed observation to the highest-numbered existing immutable `scholarship_versions` snapshot for the matched cycle at detection time. That is the cycle's latest canonical working baseline, whether its effective verification is pending, verified or rejected. Phase 2 creates a version on every admin mutation; Phase 2.2 makes every new version pending and intentionally does not equate current publication with latest working state. Record `baseline_version_id` and version number on every proposal. If there is no version, it is first discovery and requires new-record review. At approval, lock the cycle and re-check that the latest version still equals the recorded baseline. If it changed, do not silently rebase: mark the task stale/cancelled and create a fresh processing/detection task against the new baseline.

Field policy:

| Field group | Default materiality rule |
|---|---|
| Opening date, deadline, application URL | Any normalized value change is material. |
| Funding amounts, currencies/periods, classification, benefits | Any typed structured value change is material; unknown and explicit zero remain distinct per Phase 2. |
| Eligibility, including GPA and language scores | Any typed requirement add/remove/change is material. |
| Degree and subject | Any relation change is material. |
| Title and description | Title change is material; description change is material when normalized text differs. Cosmetic whitespace/markup-only normalization is non-material. |
| Cycle key/label and stable scholarship identity fields | Identity-changing; route to identity/cycle review and never silently change a key or merge identity. |
| Unrecognized/non-canonical fields | Store as extracted evidence only; no canonical proposal until an explicit field mapping/policy exists. |

Materiality is a versioned field-policy registry in application code/configuration, with the above baseline rules documented and covered by tests. It is not a user-editable score and cannot be inferred by confidence. Non-material changes may be recorded as proposals and reviewed; they do not create a new version unless an authorized reviewer elects to apply them under the same approval workflow. Never discard evidence silently.

`proposed_changes` contains: `id`; nullable `target_scholarship_id` and `target_cycle_id` until identity resolution; nullable `baseline_version_id` only for first discovery; required `processing_run_id`, `raw_observation_id`, and field path; `old_value` as exact baseline JSON (null for absent); `proposed_value` as normalized JSON; `extracted_value` JSON; `display_old`/`display_new` human-readable strings; normalization version; evidence locator; detection reason; materiality (`material`, `non_material`, `identity`); state (`pending`, `approved`, `rejected`, `needs_evidence`, `cancelled`, `stale`); creation time. JSON preserves typed arrays/objects and null-vs-zero; display values are not authoritative. Preserve explicit source URL and observation through the run/provenance relationship.

Rows are not deleted. Workflow state may change only as part of a locked review transition; `review_decisions` is the append-only source of every transition. `old_value`, proposed/extracted values, baseline and evidence are immutable after creation. Proposals never write canonical tables directly.

## 8. Human review workflow

`review_tasks` group one processing run's proposals for one target cycle/identity decision. A task can contain multiple field proposals so reviewers see a coherent cycle change and approval creates one complete snapshot. New-program discovery may have no target cycle and uses a `new_record` task. Duplicate resolution uses a `duplicate_resolution` task tied to its candidate. Task fields include ID, task type, target scholarship/cycle nullable, processing run nullable only for manual/administrative review, priority, state, assignee nullable, created/updated/closed timestamps, and optimistic `lock_version`.

Supported task states: `pending`, `in_review`, `approved`, `rejected`, `needs_evidence`, `cancelled`, `stale`. Assignment is mutable workflow state; assignment changes are also appended as task events/decisions. A task's single decision applies to the complete proposal set selected on it. Partial approvals require splitting the task before review into separate coherent tasks; never create ambiguous mixed snapshots.

`review_decisions` is append-only: task ID, actor ID, outcome, reason/notes, selected evidence references, reviewed proposal IDs/snapshot, baseline version ID, created timestamp. Outcomes are `claim`, `approve`, `reject`, `needs_evidence`, `cancel`, `mark_stale`; actor/time come from server authentication/time. Reject and needs-evidence require notes; approval must reference the source observation/evidence reviewed. Reviewer compares the canonical value from the exact baseline snapshot, proposed normalized value, official URL/capture time/hash/artifact, and prior versions/provenance. Do not expose internal notes on public routes.

The review service locks the task and checks expected state/lock version. Two reviewers cannot both apply it: the first committed transition wins; the second receives a stale/conflict result and must reload. A task in `needs_evidence` can receive new evidence through a new observation/run and linked task history; the old decision remains. Closed tasks are not reopened in place; create a successor task so history stays linear and legible.

## 9. Approved change → immutable version

For an existing cycle, approval runs atomically: lock task, cycle and latest version; verify baseline still matches; validate every approved normalized value again; apply the full approved proposal set to the mutable Phase 2 working rows; construct a complete cycle snapshot using the existing `CreateScholarshipVersionService` snapshot contract; create version N+1 with actor, source and a Phase 4 change type; create the pending verification record through the existing version-creation transaction; append provenance rows for new and carried-forward fields; append review decision/audit history; close task and proposals. No partial canonical write is allowed if version/provenance/verification creation fails.

For first discovery, approval must establish the stable scholarship and cycle identities through authorized catalog creation, populate typed cycle funding/eligibility and source relationships, then create version 1 and provenance in one transaction. Duplicate resolution must finish before an identity is created where a candidate exists. Source records must already exist in the Phase 2 source registry; Phase 4 does not fetch or register a source automatically.

This matches Phase 2's immutable complete snapshots and Phase 2.2's version-specific pending verification. The new version does not inherit verification. The current `published_version_id` is untouched; existing publication remains visible under the unchanged Phase 3 predicate until an authorized Phase 2.2 publication transition explicitly replaces/unpublishes it. The approval itself never calls `PublicationService`.

## 10. Verification and publication safety

After approval, use existing Phase 2.2 `VerificationService` to append `pending` at version creation and then append an authorized `verified` or `rejected` decision. Evidence must satisfy that service's active official source and snapshot checks. Phase 4 must not add a parallel verified flag or weaken the authoritative latest-decision ordering (`decided_at DESC, id DESC`).

Publication remains the existing Phase 2.2 `PublicationService` contract: verified version, same cycle, valid lifecycle, and active official evidence; append event and atomically update pointer. Phase 3 public discovery remains: current pointer, latest effective verification `verified`, active official source, not archived, and not expired by the published snapshot deadline (with Phase 3's null-deadline behavior). A processed observation, approved proposal or new version alone cannot become public.

## 11. Transaction boundaries, idempotency and concurrency

| Operation | Atomic boundary / replay behavior |
|---|---|
| Observation ingestion | Insert/find by `(source_id, idempotency_key)` and verify immutable hash/metadata match in one transaction. Reuse with different content is a conflict. Artifact upload is staged first; orphaned objects are cleaned by retention tooling. |
| Processing | Claim/lock a queued run; only one active executor per run key. Persist terminal extracted/normalized/validation output atomically. Retry the same in-flight run with attempt count; terminal rerun gets a new run key. |
| Duplicate/change detection | Transaction with unique keys on run-target candidate and proposal identity `(processing_run_id, target_cycle_id, field_path, baseline_version_id)`. Repeated detection returns existing rows. |
| Review decision | Lock task; compare state and lock version; append one decision and transition task/proposals atomically. Repeated approve returns existing completed result only for same actor/outcome/request key; conflicting outcome is rejected. |
| Version creation | Lock cycle, verify baseline, update working state, create complete immutable version + pending verification + provenance + audit/review linkage in one DB transaction. Unique `(cycle_id, version_number)` and row lock serialize competing changes. |
| Verification | Existing Phase 2.2 transaction/service and append-only record. |
| Publication | Existing Phase 2.2 transaction/service and append-only event/pointer update. |

Two workers processing the same observation are serialized by run identity/unique key. Concurrent duplicate detection is serialized by candidate uniqueness. Competing approvals serialize on the cycle and baseline check; only one can create the next version from a given baseline. A second task with a stale baseline becomes `stale` and must be reprocessed. Phase 4 does not implement crawler leases; Phase 5's job/lease idempotency remains upstream and maps to observation receipt idempotency.

## 12. Historical integrity

| Entity | Integrity class |
|---|---|
| Raw observations and artifacts | Append-only immutable evidence. |
| Terminal processing output | Immutable; in-flight execution status mutable; reprocessing creates another run. |
| Duplicate candidate evidence/signals | Immutable; candidate resolution is mutable state with append-only decisions. |
| Proposal values/baseline/evidence | Immutable; status is workflow state backed by append-only decisions. |
| Review task | Mutable assignment/current state; every transition/assignment decision append-only. |
| Review decisions | Append-only immutable. |
| Canonical scholarship/cycle/funding/eligibility | Mutable current working state; each Phase 4 approved update is captured by a new immutable version. |
| Scholarship versions | Immutable complete cycle snapshots. |
| Field provenance | Append-only, immutable, version/cycle/field scoped; carry-forward creates new rows. |
| Verification records/publication events | Existing Phase 2.2 append-only records. |

Evidence/version/review history uses restrictive foreign keys; deleting catalog rows or sources must not cascade away history. Actor deletion may null historical actor references as Phase 2.2 specifies. Object retention must exceed all referencing history.

## 13. Schema proposal

All tables use `BIGINT UNSIGNED` primary keys unless noted; UTC timestamps; restrictive FKs for evidence/history; no cascading deletion of evidence. State columns use DB enums only for stable, closed vocabularies; parser-controlled categories remain bounded strings plus validation to permit safe version evolution. JSON is used only for extractor/proposal values, signal details, and evidence locators whose shape is inherently variable. Add check constraints for hash format, non-negative lengths/attempts, and legal null/target combinations where MySQL supports them; enforce domain rules in services as well.

### `raw_observations` — append-only

- Columns: `id`; `source_id NOT NULL`; `observed_url VARCHAR(2048) NOT NULL`; `observed_url_hash CHAR(64) NOT NULL`; `observed_at TIMESTAMP NOT NULL`; `content_hash CHAR(64) NOT NULL`; `artifact_ref VARCHAR(1024) NOT NULL`; `content_type VARCHAR(160) NULL`; `content_length BIGINT UNSIGNED NULL`; `source_access_status ENUM(success,blocked,not_found,rate_limited,error,unknown) NOT NULL`; `producer_type VARCHAR(32) NOT NULL`; `producer_ref VARCHAR(191) NULL`; `idempotency_key VARCHAR(191) NOT NULL`; `created_at TIMESTAMP NOT NULL`.
- FK source → `scholarship_sources.id` restrict delete/update. Unique `(source_id,idempotency_key)`. Index `(source_id,content_hash,observed_at)`, `(source_id,observed_url_hash,content_hash,observed_at)`, `(source_id,observed_at)`.
- No update/delete API. Repeated key with mismatching immutable content is conflict.

### `processing_runs` — mutable in-flight, immutable terminal result

- Columns: `id`; `observation_id NOT NULL`; `run_key VARCHAR(191) NOT NULL`; `parser_name/version NOT NULL`; `normalization_version NOT NULL`; `validation_version NOT NULL`; `status ENUM(queued,running,succeeded,invalid,failed) NOT NULL`; `attempt_count UNSIGNED SMALLINT NOT NULL DEFAULT 0`; `extracted_payload JSON NULL`; `normalized_payload JSON NULL`; `validation_errors JSON NULL`; `validation_warnings JSON NULL`; `last_error_code VARCHAR(80) NULL`; `started_at`, `finished_at` nullable timestamps; `created_at`.
- FK observation restrict delete. Unique `(observation_id,run_key)`. Index `(status,created_at)`, `(observation_id,created_at)`.
- Terminal result cannot be edited; reprocess by new run key. Exact package/version and evidence locators are inside payload.

### `duplicate_candidates` — immutable evidence, mutable resolution

- Columns: `id`; `processing_run_id NOT NULL`; `candidate_scholarship_id NOT NULL`; `candidate_cycle_id NULL`; `classification ENUM(program_identity,cycle_identity) NOT NULL`; `state ENUM(possible,confirmed,rejected,resolved) NOT NULL`; `signals JSON NOT NULL`; `score DECIMAL(7,6) NULL`; `resolved_scholarship_id NULL`; `resolved_cycle_id NULL`; created/updated timestamps.
- FKs to run/scholarship/cycle; cycle/scholarship ownership must match. Add stored generated `candidate_cycle_scope = COALESCE(candidate_cycle_id,0)` and unique `(processing_run_id,candidate_scholarship_id,candidate_cycle_scope)`; 0 is reserved because real IDs are positive. Index `(state,created_at)`, `(candidate_scholarship_id,candidate_cycle_id)`.
- Each resolution appends a `review_decisions` row; signals are immutable. Do not auto-merge.

### `proposed_changes` — immutable content, mutable status

- Columns: `id`; `processing_run_id NOT NULL`; `raw_observation_id NOT NULL`; `target_scholarship_id NULL`; `target_cycle_id NULL`; `baseline_version_id NULL`; `field_path VARCHAR(191) NOT NULL`; `old_value JSON NULL`; `extracted_value JSON NULL`; `proposed_value JSON NOT NULL`; `display_old TEXT NULL`; `display_new TEXT NULL`; `normalization_version VARCHAR(80) NOT NULL`; `evidence_locator JSON NOT NULL`; `detection_reason VARCHAR(80) NOT NULL`; `materiality ENUM(material,non_material,identity) NOT NULL`; `status ENUM(pending,approved,rejected,needs_evidence,cancelled,stale) NOT NULL`; `created_at`, `updated_at`.
- FKs to run/observation/scholarship/cycle/version. Enforce same observation as run and version/cycle/scholarship ownership. Add stored generated `target_cycle_scope = COALESCE(target_cycle_id,0)` and `baseline_version_scope = COALESCE(baseline_version_id,0)`; unique `(processing_run_id,target_cycle_scope,baseline_version_scope,field_path)`. 0 is reserved for null first-discovery target/baseline. Index `(target_cycle_id,status,created_at)`, `(baseline_version_id)`, `(processing_run_id,field_path)`.
- Old/new values and evidence are immutable; no cascade deletes.

### `review_tasks` — mutable workflow state

- Columns: `id`; `task_type ENUM(change_review,new_record,duplicate_resolution,diagnostic) NOT NULL`; `target_scholarship_id NULL`; `target_cycle_id NULL`; `processing_run_id NULL`; `duplicate_candidate_id NULL`; `priority SMALLINT NOT NULL DEFAULT 0`; `state ENUM(pending,in_review,approved,rejected,needs_evidence,cancelled,stale) NOT NULL`; `assignee_id NULL`; `lock_version UNSIGNED INT NOT NULL DEFAULT 0`; created/updated/closed timestamps.
- FKs to catalog/run/candidate/user, restrictive for history. Index `(state,priority,created_at)`, `(assignee_id,state,created_at)`, `(target_cycle_id,state)`. One open coherent task per processing run/target; enforce with generated nullable-safe scope key or transactional uniqueness strategy.
- One task may have many proposals through `review_task_proposed_change` (`review_task_id`, `proposed_change_id`, `created_at`; composite PK, restrictive FKs). All proposals in one task share target and baseline.

### `review_decisions` — append-only

- Columns: `id`; `review_task_id NOT NULL`; `actor_id NULL` (system only for diagnostic transition); `outcome ENUM(claim,approve,reject,needs_evidence,cancel,mark_stale,assign,unassign) NOT NULL`; `reason TEXT NULL`; `evidence_refs JSON NULL`; `baseline_version_id NULL`; `request_key VARCHAR(191) NOT NULL`; `created_at TIMESTAMP NOT NULL`.
- FKs task/actor/baseline restrict (actor follows historical null policy). Unique `(review_task_id,request_key)`. Index `(review_task_id,id)`, `(actor_id,created_at)`.
- No updates/deletes. Task current state/assignee is a projection of decisions and may be updated transactionally.

`review_decision_proposals` preserves the exact proposal set considered by a decision: `review_decision_id`, `proposed_change_id`, `created_at`; composite primary key and restrictive foreign keys with a reverse index on `proposed_change_id`. This keeps proposal membership relationally auditable.

### `field_provenance` — append-only, version-scoped

- Columns: `id`; `scholarship_id NOT NULL`; `cycle_id NOT NULL`; `version_id NOT NULL`; `field_path VARCHAR(191) NOT NULL`; `source_id NULL`; `source_url_snapshot VARCHAR(2048) NULL`; `raw_observation_id NULL`; `processing_run_id NULL`; `observed_at NULL`; `extraction_method VARCHAR(80) NOT NULL`; `parser_version VARCHAR(80) NULL`; `review_state ENUM(approved,manual_recorded,carried_forward) NOT NULL`; `provenance_key CHAR(64) NOT NULL`; `evidence_locator JSON NULL`; `created_at`.
- Add unique `(scholarship_versions.id,cycle_id,scholarship_id)` to `scholarship_versions` for composite ownership FK `(version_id,cycle_id,scholarship_id)`; source/observation/run FKs restrict delete. Unique `(version_id,field_path,provenance_key)` where the key is a deterministic hash of origin + source + observation + run + evidence locator; manual provenance uses an explicit stable origin key instead of nullable uniqueness. Index `(scholarship_id,cycle_id,field_path,version_id)`, `(raw_observation_id)`, `(source_id)`.
- No verification status column; join current/effective version-specific verification record. No update/delete.

### Existing `scholarship_versions`

Do not rename or alter ownership. Phase 2 schema remains `(scholarship_id,cycle_id,version_number,snapshot,change_type,origin_type,actor_id,source_id,created_at)`, unique `(cycle_id,version_number)`, immutable and same-cycle constrained. Phase 4 adds a review-decision reference only if needed for direct navigation; authoritative linkage already exists via provenance and review task, so a new version column is not required. The approval transaction creates the existing Phase 2.2 pending verification record and leaves `published_version_id` unchanged.

## 14. Relationship map

```text
scholarship_sources
  └─ raw_observations (append-only artifact evidence)
       └─ processing_runs (repeatable parser/normalizer/validator executions)
            ├─ duplicate_candidates ─ review task ─ review decisions
            └─ proposed_changes ─┘
                     │
                     └─ approval transaction → scholarship/cycle working rows
                                               → scholarship_versions (immutable)
                                               → field_provenance (immutable)
                                               → verification_records (Phase 2.2)
                                               → explicit publication_events/pointer (Phase 2.2 only)
```

`raw_observations` has many runs; each run has many candidates/proposals; a review task groups proposal rows and/or a duplicate candidate; each task has many decisions; each cycle has many immutable versions; each version has many provenance and verification records; publication events reference same-cycle versions. No public discovery result reads proposals, review tasks, processing output or raw observations.

## 15. Service boundaries

- `ObservationIngestionService`: validate source ownership, artifact/hash, URL and idempotency; persist observation only.
- `ObservationProcessor`: coordinate parser adapter, normalization, validation and terminal processing result. Parser is an interface; no crawler or AI provider dependency.
- `NormalizationService` and `ValidationService`: deterministic, versioned domain components; validation returns typed errors, never writes canonical data.
- `DuplicateDetectionService`: compare normalized identity signals and persist explainable candidates; no merge.
- `ChangeDetectionService`: resolve target, select exact latest-version baseline, apply field policy, persist proposals/tasks idempotently.
- `ReviewService`: assignment, locking, decisions, stale/evidence transitions and audit.
- `ApprovedChangeService` / `VersionBuilder`: lock cycle, revalidate baseline/proposals, update Phase 2 mutable working rows and use the existing complete snapshot creation contract; persist provenance and review linkage atomically.
- Existing `VerificationService` and `PublicationService` remain sole owners of verification/publication transitions. Do not duplicate them in Phase 4.

HTTP controllers/requests/resources are transport adapters with policy checks. Phase 4 needs only authenticated admin review endpoints and an internal observation/processor boundary for fixtures and future Phase 5; it adds no public API contract. Exact route design is an implementation choice within `/api/v1/admin` and existing worker-auth separation.

## 16. Test strategy

Use MySQL 8 integration coverage for JSON, unique constraints, FK ownership, and transaction/concurrency semantics; isolated SQLite tests may supplement but do not prove MySQL behavior. Do not run destructive migrations against the primary database. Test using synthetic artifact fixtures and registered test sources.

| Area | Required cases |
|---|---|
| Observation | Same idempotency key replay returns one row; same key/different hash conflicts; same bytes at later capture creates history/no-op; changed bytes create distinct observation; failed/blocked capture cannot yield proposals; artifact/hash mismatch rejected. |
| Processing | Same run key replay is single execution; concurrent processors cannot execute same run; terminal result immutable; failed rerun creates new run; parser/normalizer versions preserved; extracted/normalized/validated values remain distinct. |
| Duplicate | Exact URL/title/university candidate; probable signal set; distinct program rejected by review; same program/new cycle resolves to cycle, not scholarship duplicate; missing signals do not imply mismatch; concurrent candidate uniqueness. |
| Change | Same normalized values no proposal; cosmetic text change classified per policy; deadline/open date/funding/eligibility/GPA/language/application URL/degree/subject/description/benefit changes follow documented materiality; first discovery has no false baseline; stale baseline prevents approval. |
| Review | Approve creates one complete version; reject does not mutate canonical state; needs evidence preserves history; assignment/audit; one task with multiple proposals; two reviewers race and only one transition commits; repeated approve idempotent. |
| Version/provenance | N+1 complete snapshot; prior snapshot and provenance immutable; evidence and parser versions preserved; carried-forward provenance links; pending verification created; concurrent approvals serialize version numbering. |
| Publication | Approval does not move published pointer; pending/unpublished version is not public; existing published version stays public; verified and explicitly published version follows Phase 2.2; rejected/pending latest verification and inactive source remain hidden; Phase 3 deadline/archive predicate unchanged. |

## 17. Phase 5 handoff contract

Phase 5 must register/select an existing `scholarship_sources` row, capture exact response bytes, calculate SHA-256, store bytes in protected object storage, and submit `source_id`, exact `observed_url`, trusted `observed_at`, hash, artifact reference, content metadata, access outcome, stable producer/job/attempt reference and replay-stable `idempotency_key`. It must preserve the response even if unchanged when it is a distinct successful capture. Phase 4 validates/persists the observation and processes it independently.

Phase 5 owns worker authentication, jobs, attempts, leases, scheduling, source rate/access controls and transport retries. It must never connect directly to MySQL or write scholarship/cycle/funding/eligibility/version/proposal/review/verification/publication tables. It has no dependency on duplicate signals, thresholds, proposals, reviewers, verification decisions or publication pointers. Contract evolution is versioned and backward-compatible; any breaking observation change requires an explicit architecture revision before Phase 5 adoption.

## 18. Readiness

This specification resolves the phase-order boundary and fixes the observation, baseline, duplicate, review, provenance, version, verification and publication contracts. The implementation follows these invariants and was verified against the isolated MySQL test schema; concrete implementation and verification details are recorded in `docs/setup/phase-4-verification.md`.
