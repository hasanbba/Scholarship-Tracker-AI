<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scholarship extends Model
{
    use HasFactory;

    protected $fillable = ['university_id', 'title', 'slug', 'description', 'official_url', 'publication_status', 'lifecycle_status'];

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(ScholarshipCycle::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class)->withTimestamps();
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ScholarshipSource::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ScholarshipVersion::class);
    }
}
