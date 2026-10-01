# Phase 4 Architecture Review

## Current status

**PHASE 4 STATUS: IMPLEMENTED AND VERIFIED** — architecture review found no blocking conflict; implementation and verification are documented in `phase-4-verification.md`.

## Files reviewed

- `docs/architecture/phase-0-foundation.md`
- `docs/architecture/phase-0.1-architecture-patch.md`
- `docs/architecture/phase-2-scholarship-core.md`
- `docs/architecture/phase-2.2-verification-publication.md`
- `docs/architecture/phase-3-search-discovery.md`
- Current Phase 2 core and Phase 2.2 migrations, models, version/verification/publication services, discovery query, and Phase 2.2/3 setup verification notes.

## Decisions and resolved blockers

1. **Phase 4/5 sequencing:** Phase 4 defines and consumes the observation input contract; synthetic observations/fixtures are sufficient to implement and test it. Phase 5 later implements the crawler producer that conforms to the contract. Phase 4 does not depend on crawler internals.
2. **Observation identity:** `source_id + producer idempotency_key` provides replay identity. Content hash identifies repeated bytes but is not unique, so later unchanged captures remain in history.
3. **Processing:** Each observation can have multiple parser/normalization/validation-versioned runs. Terminal outputs are immutable; reprocessing creates a new run.
4. **Duplicate handling:** Explainable signals create candidate pairs for human resolution; no numerical threshold or automatic merge is specified. New cycle identity is distinguished from duplicate program identity.
5. **Change baseline:** Compare against the highest-numbered immutable version for the target cycle at detection. Approval locks and rechecks that baseline; a stale proposal is not silently rebased.
6. **Review and versioning:** A task may group coherent same-cycle proposals. Approval atomically updates Phase 2 working rows, creates the complete next immutable version, carries provenance forward, appends review history and creates the Phase 2.2 pending verification record.
7. **Verification/publication:** Existing Phase 2.2 services remain authoritative. New versions do not inherit verification, approval never publishes, and the current published pointer remains unchanged until an explicit Phase 2.2 publication action. Phase 3 visibility is unchanged.
8. **Schema/history:** Phase 0 logical entities are retained with exact Phase 4 contracts in `phase-4-data-quality-review.md`; prior evidence, processing, proposal, review, version, verification, publication and provenance history is not overwritten.

## Dependency order

Phase 2 source registry and catalog/version schema → Phase 4 observation contract, processing, validation, duplicate/change review, approved version creation and provenance → existing Phase 2.2 verification → explicit Phase 2.2 publication → unchanged Phase 3 public discovery. Phase 5 can be developed after the contract and produces observations for Phase 4.

## Implementation decisions

- Implementation began after the explicit Phase 4 start instruction.
- Use synthetic observation artifacts and test sources; no live crawler is required.
- Preserve the exact input/idempotency contract, latest-version baseline and stale-baseline rule.
- Use the existing Phase 2 snapshot creation semantics and Phase 2.2 verification/publication services.
- Validate FK/unique/index/locking behavior on the supported MySQL 8 runtime using an isolated test schema.
- Phase 5 must conform to the observation contract and remain unable to write canonical or review state directly.

The architecture review resolved the previously identified Phase 4/5 dependency ambiguity by making the observation contract the stable boundary. It found no conflict requiring a change to the Phase 2, 2.2, or 3 contracts. The full schema, relationships, service boundaries and test matrix are in `docs/architecture/phase-4-data-quality-review.md`.

Phase 4 implementation details, verification evidence, and limitations are in `phase-4-verification.md`.

