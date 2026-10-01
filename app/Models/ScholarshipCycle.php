<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScholarshipCycle extends Model
{
    use HasFactory;

    protected $fillable = ['scholarship_id', 'cycle_key', 'label', 'opening_date', 'deadline', 'application_url', 'status'];

    protected function casts(): array
    {
        return ['opening_date' => 'date', 'deadline' => 'date'];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function funding(): HasOne
    {
        return $this->hasOne(ScholarshipFunding::class, 'cycle_id');
    }

    public function eligibilityRules(): HasMany
    {
        return $this->hasMany(ScholarshipEligibilityRule::class, 'cycle_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ScholarshipVersion::class, 'cycle_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ScholarshipVersion::class, 'published_version_id');
    }

    public function publicationEvents(): HasMany
    {
        return $this->hasMany(PublicationEvent::class, 'cycle_id');
    }

    public function proposedChanges(): HasMany
    {
        return $this->hasMany(ProposedChange::class, 'target_cycle_id');
    }

    public function reviewTasks(): HasMany
    {
        return $this->hasMany(ReviewTask::class, 'target_cycle_id');
    }

    public function fieldProvenance(): HasMany
    {
        return $this->hasMany(FieldProvenance::class, 'cycle_id');
    }
}
