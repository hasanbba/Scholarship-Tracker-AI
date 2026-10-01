<?php

namespace App\Services\DataQuality;

use App\Models\Country;
use App\Models\Degree;
use App\Models\Subject;
use App\Models\University;

class CandidateValidationService
{
    private const RULE_TYPES = ['nationality', 'degree', 'subject', 'field', 'gpa', 'gpa_scale', 'percentage', 'ielts', 'toefl', 'pte', 'duolingo', 'gre', 'gmat', 'age', 'work_experience', 'graduation_year', 'academic_background', 'other'];

    private const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', '=', '!=', '>', '>=', '<', '<='];

    public function validate(array $candidate): array
    {
        $errors = [];
        $scholarship = $candidate['scholarship'];
        $cycle = $candidate['cycle'];

        if (! is_int($scholarship['university_id']) || ! University::query()->whereKey($scholarship['university_id'])->where('status', 'active')->exists()) {
            $errors[] = ['field' => 'scholarship.university_id', 'code' => 'university_missing_or_inactive'];
        }
        if (! is_string($scholarship['title']) || $scholarship['title'] === '' || mb_strlen($scholarship['title']) > 220) {
            $errors[] = ['field' => 'scholarship.title', 'code' => 'title_required_or_too_long'];
        }
        if (! is_string($cycle['cycle_key']) || $cycle['cycle_key'] === '' || mb_strlen($cycle['cycle_key']) > 80) {
            $errors[] = ['field' => 'cycle.cycle_key', 'code' => 'cycle_key_required_or_too_long'];
        }
        foreach (['opening_date', 'deadline'] as $date) {
            if ($cycle[$date] !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $cycle[$date])) {
                $errors[] = ['field' => 'cycle.'.$date, 'code' => 'invalid_date'];
            }
        }
        if ($cycle['opening_date'] !== null && $cycle['deadline'] !== null && $cycle['opening_date'] > $cycle['deadline']) {
            $errors[] = ['field' => 'cycle.deadline', 'code' => 'deadline_before_opening'];
        }

        foreach ($candidate['subjects'] as $subjectId) {
            if (! Subject::query()->whereKey($subjectId)->where('status', 'active')->exists()) {
                $errors[] = ['field' => 'subjects', 'code' => 'subject_missing_or_inactive'];
                break;
            }
        }
        foreach ($candidate['eligibility_rules'] ?? [] as $index => $rule) {
            $value = $rule['normalized_value'] ?? null;
            $listOperator = in_array($rule['operator'] ?? null, ['in', 'not_in'], true);
            if (! in_array($rule['rule_type'] ?? null, self::RULE_TYPES, true)
                || ! in_array($rule['operator'] ?? null, self::OPERATORS, true)
                || ($listOperator && (! is_array($value) || ! array_is_list($value) || $value === []))
                || (! $listOperator && (! is_scalar($value) || $value === ''))) {
                $errors[] = ['field' => "eligibility_rules.$index", 'code' => 'invalid_typed_requirement'];

                continue;
            }
            if (isset($rule['degree_id']) && ! Degree::query()->whereKey($rule['degree_id'])->where('status', 'active')->exists()) {
                $errors[] = ['field' => "eligibility_rules.$index.degree_id", 'code' => 'degree_missing_or_inactive'];
            }
            if (isset($rule['subject_id']) && ! Subject::query()->whereKey($rule['subject_id'])->where('status', 'active')->exists()) {
                $errors[] = ['field' => "eligibility_rules.$index.subject_id", 'code' => 'subject_missing_or_inactive'];
            }
            if (isset($rule['country_id']) && ! Country::query()->whereKey($rule['country_id'])->exists()) {
                $errors[] = ['field' => "eligibility_rules.$index.country_id", 'code' => 'country_not_found'];
            }
            $range = match ($rule['rule_type'] ?? null) {
                'ielts' => [0, 9],
                'toefl' => [0, 120],
                'duolingo' => [10, 160],
                'percentage' => [0, 100],
                default => null,
            };
            if ($range !== null && is_numeric($value) && ((float) $value < $range[0] || (float) $value > $range[1])) {
                $errors[] = ['field' => "eligibility_rules.$index.normalized_value", 'code' => 'score_out_of_range'];
            }
        }

        $allowedFunding = ['classification', 'notes'];
        foreach (['tuition', 'stipend', 'accommodation', 'health_insurance', 'travel', 'visa', 'research_grant', 'application_fee', 'other_benefits'] as $benefit) {
            array_push($allowedFunding, $benefit.'_amount', $benefit.'_currency', $benefit.'_period');
        }
        foreach (array_keys($candidate['funding']) as $key) {
            if (! in_array($key, $allowedFunding, true)) {
                $errors[] = ['field' => 'funding.'.$key, 'code' => 'unknown_funding_field'];
            }
        }
        foreach ($candidate['funding'] as $key => $value) {
            if (str_ends_with($key, '_amount') && $value !== null && (! is_numeric($value) || (float) $value < 0)) {
                $errors[] = ['field' => 'funding.'.$key, 'code' => 'invalid_money_amount'];
            }
            if (str_ends_with($key, '_currency') && $value !== null && ! preg_match('/^[A-Z]{3}$/', (string) $value)) {
                $errors[] = ['field' => 'funding.'.$key, 'code' => 'invalid_currency'];
            }
        }
        if (isset($candidate['funding']['classification']) && ! in_array($candidate['funding']['classification'], ['fully_funded', 'partially_funded', 'tuition_only', 'stipend_only', 'mixed', 'unknown'], true)) {
            $errors[] = ['field' => 'funding.classification', 'code' => 'invalid_funding_classification'];
        }

        return $errors;
    }
}
