<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class DuplicateCandidate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['signals' => 'array', 'score' => 'decimal:6'];
    }

    protected static function booted(): void
    {
        static::updating(function (DuplicateCandidate $candidate): void {
            $allowed = ['state', 'resolved_scholarship_id', 'resolved_cycle_id', 'updated_at'];
            if (array_diff(array_keys($candidate->getDirty()), $allowed) !== []) {
                throw new LogicException('Duplicate candidate evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Duplicate candidate history cannot be deleted.'));
    }

    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class);
    }

    public function candidateScholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class, 'candidate_scholarship_id');
    }

    public function candidateCycle(): BelongsTo
    {
        return $this->belongsTo(ScholarshipCycle::class, 'candidate_cycle_id');
    }

    public function reviewTask(): HasOne
    {
        return $this->hasOne(ReviewTask::class);
    }
}
