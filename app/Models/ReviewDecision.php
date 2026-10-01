<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

class ReviewDecision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence_refs' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Review decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Review decisions cannot be deleted.'));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ReviewTask::class, 'review_task_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function proposals(): BelongsToMany
    {
        return $this->belongsToMany(ProposedChange::class, 'review_decision_proposals')->withPivot('created_at');
    }
}
