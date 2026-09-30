<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipSource extends Model
{
    use HasFactory;

    protected $fillable = ['scholarship_id', 'university_id', 'source_type', 'source_name', 'source_url', 'source_url_hash', 'is_primary', 'status', 'notes'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
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
}
