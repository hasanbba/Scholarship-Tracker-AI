<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ProcessingRun extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'extracted_payload' => 'array',
            'normalized_payload' => 'array',
            'validation_errors' => 'array',
            'validation_warnings' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ProcessingRun $run): void {
            if (in_array($run->getOriginal('status'), ['succeeded', 'invalid', 'failed'], true)) {
                throw new LogicException('Terminal processing results are immutable. Reprocess with a new run key.');
            }
        });
        static::deleting(fn () => throw new LogicException('Processing history cannot be deleted.'));
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(RawObservation::class, 'observation_id');
    }

    public function duplicateCandidates(): HasMany
    {
        return $this->hasMany(DuplicateCandidate::class);
    }

    public function proposedChanges(): HasMany
    {
        return $this->hasMany(ProposedChange::class);
    }
}
