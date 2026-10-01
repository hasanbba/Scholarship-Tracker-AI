# Phase 5B.2 Safe Fetch Engine

**Phase 5B.2 fetches and stores bounded raw artifacts. It does not extract scholarship data.** The boundary ends at a local `FetchResult`; it has no Laravel completion/upload endpoint and does not create Phase 4 observations.

## Claim contract extension

The Phase 5A claim endpoint originally returned a requested URL and allowed path but omitted the registered source origin. The worker cannot safely infer registration from the requested URL itself. The claim response now additively includes `source_id`, `registered_source_url`, `source_concurrency_limit`, and `robots_policy`, retaining the existing fields. The worker requires those fields and fails closed if absent or malformed. The server remains authoritative for job admission and active source concurrency.

## Pipeline

1. Claim a fenced Phase 5A job and require its source metadata.
2. Validate HTTP(S), exact registered origin/effective port, allowed path segment boundary, HTTPS policy, and resolved destination addresses.
3. Resolve DNS and reject the request if any A/AAAA answer is not globally routable. Pin cURL to one of the validated answers with `CURLOPT_RESOLVE`; TLS verification and hostname/SNI remain enabled.
4. Fetch same-origin `/robots.txt`, cache its parsed rules in memory for one hour, and apply Allow/Disallow rules. A 404 is treated as an empty policy; inaccessible or malformed policy fails closed.
5. Fetch with an honest `ScholarshipTrackerCrawler/0.1.0` User-Agent, 5-second default connect timeout, 30-second per-request timeout, 90-second whole-fetch operation deadline, no automatic redirects, and bounded retries. Response headers are separately capped at 64 KiB.
6. For every redirect, resolve relative Location and repeat origin, scheme, HTTPS, port, path, DNS/IP validation. HTTPS downgrade and all off-origin redirects are rejected.
7. Stream accepted 2xx bytes into an ignored spool entry while incrementally calculating SHA-256. Enforce a 4 MiB hard limit. Only HTML, XHTML, JSON and PDF are accepted by default; missing/unknown MIME and compressed content are rejected.
8. Finalize the artifact and write a safe manifest containing job/attempt, URL references, status, MIME, size, digest, timestamps, redirects, robots result and bounded response metadata.

## Address and rebinding policy

The worker rejects loopback, private, link-local, carrier-grade NAT, reserved/unspecified, multicast, IPv4-mapped unsafe IPv6, IPv6 non-global-unicast and metadata destinations. It examines every DNS answer, not only the first. The cURL connection is pinned to a validated address to prevent a second DNS lookup from changing the actual destination. It uses the first approved address; if that address cannot connect it retries boundedly without trying unvalidated destinations. The system DNS resolver has OS-controlled resolver timing; PHP exposes no portable per-query timeout here, so connect/total cURL limits begin after resolution. DNS lookups may therefore take longer than the configured cURL timeout.

## Robots, rate and retries

Robots `Disallow` is honored with longest-match semantics and Allow wins ties. No user-agent spoofing or robots bypass is present. Robots response cache is process-local and expires after one hour. Each worker keeps one active fetch at a time and enforces a configurable minimum per-source and per-origin interval (default one second); the server enforces the configured per-source concurrency limit across claims. The current server schema has no global per-origin interval, so cross-PC pacing for the same origin is not coordinated.

Retries are capped at two fetch retries (three attempts), use the existing exponential backoff+jitter helper, and honor bounded `Retry-After` for 408/429/selected 5xx. A connection/DNS retry is stopped after any artifact bytes were written; partial data is deleted rather than appended to a retry. Unsafe URL/redirect, robots denial/unavailable, TLS failure, unsupported MIME, and oversize responses are permanent for this execution. `once` exits with a worker error on fetch failure; `poll` records the failure and waits for that lease to expire before claiming another job.

## Result and limitations

Stable failure codes include `INVALID_URL`, `UNREGISTERED_ORIGIN`, `PATH_NOT_ALLOWED`, `DNS_FAILURE`, `UNSAFE_IP`, `SSRF_BLOCKED`, `HTTPS_POLICY_VIOLATION`, `REDIRECT_BLOCKED`, `REDIRECT_LIMIT`, `ROBOTS_BLOCKED`, `ROBOTS_UNAVAILABLE`, `TIMEOUT`, `CONNECTION_FAILED`, `TLS_FAILED`, `HTTP_401`, `HTTP_403`, `HTTP_404`, `HTTP_429`, `HTTP_5XX`, `UNSUPPORTED_CONTENT_TYPE`, `RESPONSE_TOO_LARGE`, `MALFORMED_RESPONSE`, and `LEASE_LOST`.

The Phase 5A API has heartbeat but no completion or result submission endpoint. The worker heartbeats during a transfer at the configured interval, validates lease generation, then stores a local result and stops. It never marks the job complete. A lost heartbeat aborts cURL and deletes partial spool data. After fetch failure, it records a structured failure log and deletes partial bytes. Lease reconciliation/retry remains server-side. The test harness uses mock DNS and fake HTTP responses; it does not use public DNS or external sites.

Known limits: DNS resolver timing is OS-controlled; robots rules implement the common user-agent/Allow/Disallow path patterns but are not a complete RFC parser; rate spacing is local to a worker process; artifacts remain local until Phase 5C. No arbitrary links are discovered or followed.
