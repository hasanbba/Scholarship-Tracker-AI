<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

class ProposedChange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'extracted_value' => 'array',
            'proposed_value' => 'array',
            'evidence_locator' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ProposedChange $change): void {
            if (array_diff(array_keys($change->getDirty()), ['status', 'updated_at']) !== []) {
                throw new LogicException('Proposal content and baseline are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Proposed changes cannot be deleted.'));
    }

    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class);
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(RawObservation::class, 'raw_observation_id');
    }

    public function baselineVersion(): BelongsTo
    {
        return $this->belongsTo(ScholarshipVersion::class, 'baseline_version_id');
    }

    public function task(): BelongsToMany
    {
        return $this->belongsToMany(ReviewTask::class, 'review_task_proposed_change')->withPivot('created_at');
    }
}
