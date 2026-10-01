<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewTask extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['closed_at' => 'immutable_datetime'];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class, 'target_scholarship_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ScholarshipCycle::class, 'target_cycle_id');
    }

    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class);
    }

    public function duplicateCandidate(): BelongsTo
    {
        return $this->belongsTo(DuplicateCandidate::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function proposals(): BelongsToMany
    {
        return $this->belongsToMany(ProposedChange::class, 'review_task_proposed_change')->withPivot('created_at');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ReviewDecision::class);
    }
}
