# Phase 5C setup and verification

Apply the additive result-receipt migration using the project's normal migration process. For integration verification, configure the test process for `scholarship_tracker_test`; never point destructive test setup at `scholarship_tracker`.

Activate or rotate a crawler worker through the existing admin workflow. Keep its bearer token in the configured Windows credential store. Configure the Windows worker with the Laravel API base URL, local spool directory, and safe fetch settings from the Phase 5B.2 guide. The API accepts raw artifact bytes (maximum 4 MiB) followed by JSON result submission; it does not accept arbitrary browser/admin sessions.

Successful HTML/JSON observations use the existing Phase 4 JSON parser. PDF remains a private observation and records `unsupported_parser`. Verify artifacts through the authorized Phase 4 evidence access path. Do not expose the private storage directory through a web server or create a public storage link for it.

The worker’s HTTP calls retry bounded transient failures using stable attempt/result keys. A lost acknowledgment may leave a finalized local spool entry for operator recovery. Lease fencing rejects upload/result attempts after expiry or reassignment.

**Phase 5C does not verify or publish scholarships.** No real external website is needed for tests; worker unit tests use fake HTTP/DNS, while the integration gate below uses only loopback services.

## Live local Worker to Laravel integration gate

`tests/Feature/Phase5CLocalIntegrationTest.php` runs the real worker application and `CrawlerApiClient` against a temporary local Laravel HTTP server over cURL. It creates its worker, source, three jobs, attempts, observations, processing runs, and events in the test database using `DatabaseMigrations`; the test removes its uniquely content-addressed artifact files and stops both temporary HTTP servers in `finally` cleanup.

The test-only fixture server returns deterministic HTML from a temporary file. A test-only fetch adapter routes the worker fetch request to that loopback fixture over HTTP; the real `SafeFetcher` URL/origin/path policy still runs against a synthetic registered HTTPS origin, with a test DNS resolver returning a public test address. This confines local routing to the test and does not relax production SSRF or source URL rules. Worker claim, artifact upload, result submission, authenticated API handling, Phase 4 observation ingestion/processing, and job/attempt completion all use the real Laravel HTTP endpoints.

The test verifies three fetches: identical content at the same source URL reuses one observation; a subsequent changed body/hash creates a second observation; replaying the completed result returns its receipt without creating another observation. HTML enters the existing JSON parser and is retained with the parser's expected invalid-JSON result. Artifacts stay on Laravel's private local disk. No external host is contacted.
