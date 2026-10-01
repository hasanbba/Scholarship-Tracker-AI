<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CrawlerWorker extends Model
{
    protected $fillable = ['uuid', 'label', 'software_version', 'protocol_version', 'status', 'last_heartbeat_at', 'activated_at', 'disabled_at'];

    protected function casts(): array
    {
        return ['last_heartbeat_at' => 'datetime', 'activated_at' => 'datetime', 'disabled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $worker): void {
            $worker->uuid ??= (string) Str::uuid();
        });
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(CrawlerWorkerCredential::class, 'worker_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CrawlAttempt::class, 'worker_id');
    }

    public function presence(): string
    {
        if ($this->status !== 'active') {
            return 'disabled';
        }
        if (! $this->last_heartbeat_at || $this->last_heartbeat_at->lt(now()->subSeconds(config('crawler.worker_stale_after_seconds')))) {
            return 'stale';
        }

        return $this->attempts()->whereIn('state', ['leased', 'processing'])->where('lease_expires_at', '>', now())->exists() ? 'busy' : 'idle';
    }
}
