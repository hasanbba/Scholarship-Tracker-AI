# Phase 2.2 — Verification and Publication Foundation

## Architecture ownership

```text
Funding      → cycle-owned
Eligibility  → cycle-owned
Verification → version-specific immutable decision records
Publication  → cycle current-version pointer + append-only history
Version      → immutable content snapshot
```

`scholarship_versions` remains an immutable snapshot. Verification is recorded in `verification_records` and never written onto the version. New versions receive a `pending` record in the version creation transaction. Existing versions without a verification record are treated as pending. Funding and eligibility remain attached to `cycle_id`; neither table receives `version_id`.

## Verification records and effective decision

Each verification record belongs to one version and may reference an active official `scholarship_source`. A verification or rejection operation appends a new record with the server actor and timestamp. Client supplied actor IDs and timestamps are rejected. Rejection requires internal notes; verification requires an evidence source present in that version's snapshot and still active for the same scholarship.

Statuses are `pending`, `verified`, and `rejected`. Effective decision ordering is exactly `decided_at DESC, id DESC`. The greatest timestamp wins; the greatest primary key breaks timestamp ties. Records cannot be updated or deleted through the model, and the database restricts deletion of referenced versions and evidence sources.

## Cycle publication and history

`scholarship_cycles.published_version_id` is nullable and identifies the one current public version for that cycle. The database composite foreign key pairs `(published_version_id, cycle.id)` with `(scholarship_versions.id, scholarship_versions.cycle_id)`, preventing a cross-cycle pointer.

`publication_events` is append-only. A version replacement appends a `published` event for the new version and atomically swaps the pointer; the previous version and its events remain. Unpublishing appends an `unpublished` event for the current version and clears the pointer in the same transaction. The current pointer is authoritative for current state; event IDs and timestamps reconstruct prior transitions.

Cycle and version deletion is restricted by foreign keys while verification/publication history exists. Deleting an actor sets the historical actor reference to null without deleting the record. Source deletion is restricted while verification evidence references it. Scholarship and version content are not changed by verify, reject, publish, or unpublish.

## Publication eligibility and public policy

The publication service requires all of the following:

1. The version belongs to the requested cycle.
2. The version's effective verification decision is `verified`.
3. The version snapshot contains at least one active official source which remains active for the same scholarship.
4. The cycle is `open`, `closed`, or `expired`, and the scholarship is not archived. Draft and archived cycles cannot be published. Closed or expired cycles may retain or receive a publication decision for historical access.
5. A recorded application URL is valid; Phase 0 allows the URL to be absent where none is available.

Expired or closed cycles can retain a published historical version. Draft cycles cannot be published. Phase 0's default discovery rule still excludes expired cycles from active discovery; Phase 3 must use `published_version_id` and the immutable snapshot rather than mutable current catalog values. A missing verification record (including older Phase 2 versions) means pending and cannot be published. Scholarship-level `publication_status` is not used to determine public eligibility.

Material changes create a new immutable version with its own pending decision. They do not auto-verify, auto-publish, or auto-unpublish. Product-policy default: the currently published version remains public until an authorized publication decision changes the cycle pointer. New cycles start without verification or publication state.

## Permissions and API

The existing `admin` role receives `admin.access`, `scholarships.verify`, and `scholarships.publish` from the seeder. Students receive neither workflow permission. `super_admin` retains only its existing `admin.access` permission unless explicitly granted workflow permissions. Other admins are denied unless their roles receive the relevant permission.

Internal Sanctum-protected endpoints:

```text
POST /api/v1/admin/scholarship-versions/{version}/verify
POST /api/v1/admin/scholarship-versions/{version}/reject
POST /api/v1/admin/cycles/{cycle}/publish
POST /api/v1/admin/cycles/{cycle}/unpublish
```

Form Requests reject trusted-state fields. Controllers call focused transactional services; no generic update endpoint can set the cycle publication pointer. Public anonymous routes do not expose workflow operations.

## Phase 3 dependency

Phase 3 may read a cycle's `published_version_id` and its immutable version snapshot after this foundation is migrated. The Phase 3 public read path must require a current pointer, an effective verified decision, and a non-archived scholarship/cycle. Search filters and public discovery pages are outside Phase 2.2.
