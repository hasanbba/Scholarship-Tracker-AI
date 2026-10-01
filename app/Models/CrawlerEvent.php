<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CrawlerEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_type', 'source_id', 'job_id', 'attempt_id', 'worker_id', 'actor_user_id', 'details', 'occurred_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Crawler events are append-only.'));
        static::deleting(fn () => throw new LogicException('Crawler events are append-only.'));
    }
}
