<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ScholarshipVersion extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['scholarship_id', 'cycle_id', 'version_number', 'snapshot', 'change_type', 'origin_type', 'actor_id', 'source_id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Scholarship versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Scholarship versions cannot be deleted.'));
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ScholarshipCycle::class, 'cycle_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ScholarshipSource::class, 'source_id');
    }

    public function verificationRecords(): HasMany
    {
        return $this->hasMany(VerificationRecord::class, 'version_id')
            ->orderByDesc('decided_at')
            ->orderByDesc('id');
    }

    public function fieldProvenance(): HasMany
    {
        return $this->hasMany(FieldProvenance::class, 'version_id');
    }

    public function proposedChanges(): HasMany
    {
        return $this->hasMany(ProposedChange::class, 'baseline_version_id');
    }

    public function effectiveVerificationDecision(): ?VerificationRecord
    {
        return $this->verificationRecords()->first();
    }

    public function effectiveVerificationStatus(): string
    {
        return $this->effectiveVerificationDecision()?->status ?? 'pending';
    }
}
