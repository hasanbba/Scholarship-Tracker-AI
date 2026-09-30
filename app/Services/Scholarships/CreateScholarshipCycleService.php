<?php

namespace App\Services\Scholarships;

use App\Models\Scholarship;
use App\Models\ScholarshipCycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateScholarshipCycleService
{
    public function __construct(private readonly CreateScholarshipVersionService $versions) {}

    public function create(Scholarship $scholarship, array $data, User $actor): ScholarshipCycle
    {
        return DB::transaction(function () use ($scholarship, $data, $actor) {
            $cycle = $scholarship->cycles()->create($data);
            $this->versions->create($cycle, $actor, 'cycle_created');

            return $cycle->load('versions');
        });
    }
}
