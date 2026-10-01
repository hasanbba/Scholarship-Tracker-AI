<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlAttempt extends Model
{
    protected $fillable = ['job_id', 'worker_id', 'attempt_number', 'lease_generation', 'lease_token_hash', 'claim_request_key', 'state', 'requested_url', 'final_url', 'http_status', 'response_duration_ms', 'content_hash', 'content_length', 'started_at', 'heartbeat_at', 'lease_expires_at', 'completed_at', 'error_category', 'error_message', 'artifact_id', 'artifact_ref', 'artifact_content_type', 'artifact_metadata', 'result_hash', 'result_idempotency_key', 'fetch_result', 'observation_id'];

    protected $hidden = ['lease_token_hash'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'lease_expires_at' => 'datetime', 'completed_at' => 'datetime', 'artifact_metadata' => 'array', 'fetch_result' => 'array'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class, 'job_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(CrawlerWorker::class, 'worker_id');
    }
}
