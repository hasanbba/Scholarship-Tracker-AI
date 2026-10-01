<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RawObservation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Raw observations are immutable evidence.'));
        static::deleting(fn () => throw new LogicException('Raw observations cannot be deleted.'));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ScholarshipSource::class, 'source_id');
    }

    public function processingRuns(): HasMany
    {
        return $this->hasMany(ProcessingRun::class, 'observation_id');
    }
}
