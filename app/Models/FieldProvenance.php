<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FieldProvenance extends Model
{
    protected $table = 'field_provenance';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'evidence_locator' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Field provenance is immutable.'));
        static::deleting(fn () => throw new LogicException('Field provenance cannot be deleted.'));
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ScholarshipCycle::class, 'cycle_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ScholarshipVersion::class, 'version_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ScholarshipSource::class, 'source_id');
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(RawObservation::class, 'raw_observation_id');
    }

    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class, 'processing_run_id');
    }
}
