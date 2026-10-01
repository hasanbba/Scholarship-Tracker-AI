<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlJob extends Model
{
    protected $fillable = ['source_id', 'created_by_user_id', 'idempotency_key', 'state', 'priority', 'requested_url', 'available_at', 'assigned_worker_id', 'lease_expires_at', 'lease_generation', 'attempt_count', 'max_attempts', 'last_error_category', 'last_error_message', 'completed_at'];

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'lease_expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ScholarshipSource::class, 'source_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(CrawlerWorker::class, 'assigned_worker_id');
    }

    public function currentAttempt(): BelongsTo
    {
        return $this->belongsTo(CrawlAttempt::class, 'current_attempt_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CrawlAttempt::class, 'job_id');
    }
}
