# Phase 5B.1 Contract Note: Existing Phase 5A API

Inspected the Phase 5A routes, controller, services, worker credential model, and worker auth middleware before creating the standalone client.

| Operation | Actual request | Actual success response |
|---|---|---|
| Activate | `POST /api/v1/crawler/workers/activate`, JSON `{activation_code, protocol_version: 1, software_version?}`; activation code is a provisioned 64-character hex string. | HTTP 201, envelope `success/message/data`, with `data.worker.{uuid,label,status}`, `data.token` (64-character hex bearer), and `data.expires_at`. Token is one-time. |
| Current worker | `GET /api/v1/crawler/workers/me`, `Authorization: Bearer <token>`. | HTTP 200, `data.{uuid,label,status,protocol_version,last_heartbeat_at}`. |
| Claim | `POST /api/v1/crawler/jobs/claim`, Bearer auth, JSON `{claim_request_key}`. | HTTP 200, `data: null` when empty; otherwise `{job_id,attempt_id,lease_generation,lease_token,lease_expires_at,requested_url,allowed_path_prefix,source_id,registered_source_url,source_concurrency_limit,robots_policy}`. The additional registered source fields are required by Phase 5B.2; `claim_request_key` replay returns the current attempt/token. |
| Heartbeat | `POST /api/v1/crawler/jobs/{job}/attempts/{attempt}/heartbeat`, Bearer auth, JSON `{lease_token}`. | HTTP 200, `data.{lease_expires_at,lease_generation}`. |

Worker middleware hashes the bearer with SHA-256, checks expiry/revocation, enabled state, and endpoint scope. API errors use `{success:false,message,errors?}`; relevant statuses are 401, 403, 409, 422, and 429. Retry policy must not retry 401/403/409/422. Phase 5A has no server request-ID middleware or result/completion endpoint. This client sends `X-Request-ID` for its own log correlation, but does not expect a corresponding response header.

API route source: `routes/api.php`; payload/response source: `CrawlerWorkerController`; activation: `WorkerActivationService`; claim: `CrawlJobClaimService`; heartbeat: `CrawlHeartbeatService`; authentication: `AuthenticateCrawlerWorker`.
