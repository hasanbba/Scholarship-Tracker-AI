<?php

namespace App\Services\Scholarships;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateScholarshipService
{
    public function create(array $data, User $actor): Scholarship
    {
        return DB::transaction(function () use ($data) {
            $subjectIds = $data['subject_ids'] ?? [];
            unset($data['subject_ids']);
            $scholarship = Scholarship::query()->create($data);
            $scholarship->subjects()->sync($subjectIds);

            return $scholarship->load(['university.country', 'subjects']);
        });
    }
}
