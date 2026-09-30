<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'normalized_name', 'slug', 'status'];

    public function scholarships(): BelongsToMany
    {
        return $this->belongsToMany(Scholarship::class)->withTimestamps();
    }
}
