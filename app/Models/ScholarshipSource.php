<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipSource extends Model
{
    use HasFactory;

    protected $fillable = ['scholarship_id', 'university_id', 'source_type', 'source_name', 'source_url', 'source_url_hash', 'is_primary', 'status', 'notes', 'crawl_enabled', 'crawl_method', 'crawl_frequency', 'crawl_priority', 'allowed_path_prefix', 'robots_policy', 'source_concurrency_limit'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'crawl_enabled' => 'boolean', 'crawl_priority' => 'integer', 'source_concurrency_limit' => 'integer'];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ScholarshipVersion::class, 'source_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(RawObservation::class, 'source_id');
    }

    public function crawlJobs(): HasMany
    {
        return $this->hasMany(CrawlJob::class, 'source_id');
    }

    public function fieldProvenance(): HasMany
    {
        return $this->hasMany(FieldProvenance::class, 'source_id');
    }
}
