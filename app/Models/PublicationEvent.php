<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PublicationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['cycle_id', 'version_id', 'action', 'actor_id', 'reason'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Publication events are append-only.'));
        static::deleting(fn () => throw new LogicException('Publication events cannot be deleted.'));
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ScholarshipCycle::class, 'cycle_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ScholarshipVersion::class, 'version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
