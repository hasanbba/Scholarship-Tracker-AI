# Phase 6: Extraction, normalization, and validation

## Pipeline and ownership

Phase 6 extends the existing `ObservationProcessor` and immutable `ProcessingRun` history. It reads only a previously accepted observation's private artifact after the ingestion service verifies its SHA-256. It does not fetch URLs. JSON keeps the existing `JsonCandidateExtractor` path; `text/html` and `application/xhtml+xml` select the deterministic `HtmlScholarshipExtractor` (`html-v1`). Each run stores the parser/version, extracted candidate, field evidence, normalized candidate, validation errors/warnings, and timestamps.

Successful output continues through Phase 4 `ChangeDetectionService`, which creates duplicate candidates, proposals, and review tasks. Review approval remains the only path that updates canonical working data and creates an immutable scholarship version with `field_provenance`. Phase 6 does not create a scholarship, approve proposals, verify a version, or publish it.

## HTML parsing and evidence

The parser uses PHP DOM/libxml with network access disabled. It reads JSON-LD before removing scripts, then removes script/style/navigation/footer/noscript and obvious cookie/tracking elements from the derived DOM. It inspects metadata, JSON-LD `name`/`headline`/`description`/`applicationDeadline`/`startDate`, headings, paragraphs, lists, definition lists, tables, and same-origin application links. No browser or JavaScript execution is used.

Every extracted candidate field has a structured Phase 6 field entry: field path, raw value, normalized value, state, method, parser version, excerpt, locator, source URL, observation ID, artifact reference/hash, processing-run ID, and extraction timestamp. Phase 4 proposals carry those field evidence locators. The immutable raw artifact is never modified. Missing fields are represented as `missing` with null values and no evidence.

## Fields and normalization

The parser records the requested scholarship, funding, and eligibility paths in its field-status list. Fields supported by Phase 4's current canonical snapshot can be handed off through the existing payload: scholarship title/description/official URL and linked university, cycle key/opening/deadline/application URL, exact catalog subject matches, existing funding columns, and typed eligibility rules. University/country/region and unresolved degree/subject labels stay evidence-backed extraction candidates when no safe canonical link exists. Funding categories without a Phase 4 funding column (currently living expenses) remain evidence-only and are not forced into another field.

Dates are parsed only from explicit absolute dates. ISO dates, day-month-name-year, month-name-day-year, and unambiguous numeric day/month or month/day forms are supported. Numeric dates where both leading parts are at most 12 are `uncertain`; impossible explicit dates are `invalid`; relative dates remain raw/uncertain. Conflicting visible or JSON-LD title/deadline/opening-date values are retained as competing evidence and are not selected.

Funding keeps exact decimal text, explicit currency, and explicit period. GBP, EUR, USD, CAD, and AUD codes are recognized; a bare `$` is ambiguous and is left uncertain. No currency conversion occurs. `up to`/`maximum` wording is retained in normalized metadata/notes as a maximum, never a guarantee. Full tuition wording is represented as tuition-only coverage. Unstated benefits stay missing; they do not become zero.

Eligibility rules require explicit scholarship-page text. GPA retains both minimum and scale and is invalid if the minimum exceeds the scale. IELTS, TOEFL iBT, PTE, Duolingo, GRE, and GMAT values are range checked. Nationality is retained as explicit text and linked to a country only by exact catalog match. Degree aliases map only to an active existing degree level; subject labels map only to an exact active subject name. Fuzzy catalog merges and inference from general admissions content are not performed.

## Validation and review states

Each field is `missing`, `extracted`, `normalized`, `uncertain`, or `invalid`; processing-level candidate validation remains `succeeded`, `invalid`, or `failed`. Uncertain values generate validation warnings and are omitted from Phase 4 canonical proposals. Invalid values keep their raw evidence and prevent change detection. Contradictions include the field, competing values, and evidence locators in the processing result. A parser confidence score is not used.

The existing Phase 4 normalization maps comparison operators to symbols; its validator accepts both its input aliases and normalized symbols. This is needed for typed Phase 6 eligibility requirements and preserves Phase 4's existing storage contract.

## Unsupported input and AI boundary

PDF bytes and observation artifacts remain stored, but processing records `parser_name=unsupported`, `last_error_code=unsupported_parser`, and an explicit unsupported-PDF validation error. There is no PDF extraction or OCR. AI is not integrated or required. JSON remains on the existing parser path.

## Known limits

The first deterministic parser is deliberately conservative. It supports common English labels/patterns and a limited set of structured fields; it does not resolve external application portals, multilingual variants, every currency symbol, or complex eligibility prose. Multiple same-origin application links remain uncertain. Extracted country/region/living-expense facts cannot become canonical Phase 4 changes until a compatible canonical field exists. An observation with no explicit cycle key, exact university, or title remains invalid for Phase 4 handoff rather than receiving invented identity data.
