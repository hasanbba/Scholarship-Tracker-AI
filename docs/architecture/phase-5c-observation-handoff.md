# Phase 5C: Observation Handoff

Phase 5C connects the authenticated 5B.2 fetch worker to the existing Phase 4 evidence pipeline. It does not create a second observation or processing subsystem.

## Protocol

Both endpoints use a crawler bearer credential with the existing worker authentication middleware. Browser sessions and admin credentials are not accepted.

1. `POST /api/v1/crawler/jobs/{job}/attempts/{attempt}/artifacts` uploads raw bytes. The request body is the artifact, `Content-Type` is its detected media type, `Idempotency-Key` is stable for the attempt and SHA-256, and `X-Crawler-Metadata` is unpadded base64url JSON containing lease generation, UUID artifact identity, SHA-256, byte length, requested/final URL, and fetch timestamp.
2. The server locks and fences the active attempt, checks worker ownership, source/job/attempt linkage, registered origin and path scope, content type, exact body length, and SHA-256. The server creates a content-addressed path under the private `local` disk (`evidence/sha256/{prefix}/{sha256}`); client paths and filenames are never used.
3. `POST /api/v1/crawler/jobs/{job}/attempts/{attempt}/results` sends the fetch result JSON with a stable `Idempotency-Key`, lease generation, URLs, outcome, response metadata, and the artifact UUID/hash for a successful fetch. The server requires the matching accepted artifact before accepting `fetched`.

Artifacts are capped at 4 MiB and allowed types are HTML, XHTML, JSON, and PDF. The `local` filesystem root is private (`storage/app/private`); there is no public artifact URL. The existing authorized Phase 4 artifact access remains the access path.

## Phase 4 handoff and repeat crawls

For a successful fetch the server calls `ObservationIngestionService::ingest` using the registered source, final safe URL, exact uploaded bytes, fetch timestamp, and `producer_type=crawler`. The stable observation key includes source, canonical URL, and content SHA-256. The attempt references the resulting Phase 4 `raw_observations` row. Same content at the same source URL reuses the observation; changed bytes create a new immutable observation and artifact. Existing Phase 4 processing owns normalization, validation, duplicate candidates, change proposals, review tasks, and provenance.

HTML and JSON enter the existing `ObservationProcessor` and its existing structured JSON parser. HTML is therefore retained as evidence and currently ends with the existing invalid JSON processing outcome unless its body meets the parser contract. PDF is retained as evidence with a terminal `processing_runs` row carrying `last_error_code=unsupported_parser`; no PDF extraction is attempted. The schema has no `unsupported_parser` enum state, so the existing `failed` state is used with that explicit code.

The processing run key is stable per job/attempt. Existing extraction/provenance behavior is retained; Phase 5C adds no field-level facts and never marks a field verified. Review acceptance remains a Phase 4 action.

## Fencing, idempotency, and lifecycle

Every upload/result checks the authenticated worker, enabled status, current assignment, current attempt, matching generation, source eligibility, and unexpired lease. Old credentials with the existing `jobs.heartbeat` scope retain this attempt-submission capability; new activations also receive `jobs.results`.

Artifact UUID, SHA-256, upload key, result key, and result body hash are saved on the attempt. Identical artifact uploads and result submissions replay their receipt; conflicting content returns a conflict. A fetched result is committed with the observation, processing run, attempt completion, job completion, and audit events. A failed fetch stores the result and attempt history, creates no observation, and moves the job to `retry_pending` or `failed` according to retryability and configured attempts.

The worker retries API transport errors with the same logical idempotency keys. A successful local spool entry is deleted only after server result acceptance. A network failure after artifact storage leaves the spool for operator recovery; automatic cross-process pending-spool replay is not part of this increment.

## Audit and boundaries

Crawler events record upload, replay, result, observation, processing, retry, and completion transitions. Secrets and Authorization headers are not persisted in result metadata. Result submission ends at evidence ingestion and Phase 4 processing.

**Phase 5C does not verify or publish scholarships.** It does not change canonical scholarship data, verification state, publication state, public discovery, or browser/admin authentication.

Current limitations: the existing parser is JSON-oriented; HTML extraction, PDF extraction, AI, automated matching improvements, richer change analysis, and admin review UI improvements remain future work. This phase does not contact public websites during automated tests.
