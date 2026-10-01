<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VerificationRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['version_id', 'status', 'actor_id', 'source_id', 'notes', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verification records are append-only.'));
        static::deleting(fn () => throw new LogicException('Verification records cannot be deleted.'));
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ScholarshipVersion::class, 'version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ScholarshipSource::class, 'source_id');
    }
}
