<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipFunding extends Model
{
    use HasFactory;

    protected $table = 'scholarship_funding';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        $casts = [];
        foreach (['tuition', 'stipend', 'accommodation', 'health_insurance', 'travel', 'visa', 'research_grant', 'application_fee', 'other_benefits'] as $benefit) {
            $casts[$benefit.'_amount'] = 'decimal:2';
        }

        return $casts;
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ScholarshipCycle::class, 'cycle_id');
    }
}
