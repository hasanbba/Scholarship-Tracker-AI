<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasFactory;

    protected $fillable = ['region_id', 'name', 'normalized_name', 'slug', 'iso2', 'iso3', 'status'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function universities(): HasMany
    {
        return $this->hasMany(University::class);
    }
}
