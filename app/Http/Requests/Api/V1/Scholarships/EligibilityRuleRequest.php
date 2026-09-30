<?php

namespace App\Http\Requests\Api\V1\Scholarships;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EligibilityRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('admin.access');
    }

    public function rules(): array
    {
        return ['rule_type' => ['required', Rule::in(['nationality', 'degree', 'subject', 'gpa', 'gpa_scale', 'percentage', 'ielts', 'toefl', 'pte', 'duolingo', 'gre', 'gmat', 'age', 'work_experience', 'graduation_year', 'academic_background', 'other'])], 'operator' => ['required', Rule::in(['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'contains'])], 'normalized_value' => ['required'], 'unit' => ['nullable', 'string', 'max:40'], 'display_text' => ['nullable', 'string', 'max:500'], 'degree_id' => ['nullable', 'exists:degrees,id'], 'subject_id' => ['nullable', 'exists:subjects,id'], 'country_id' => ['nullable', 'exists:countries,id'], 'metadata' => ['nullable', 'array']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $value = $this->input('normalized_value');
            $isListOperator = in_array($this->input('operator'), ['in', 'not_in'], true);
            if ($isListOperator) {
                if (! is_array($value) || ! array_is_list($value) || $value === []) {
                    $validator->errors()->add('normalized_value', 'List operators require a non-empty array value.');
                } elseif (collect($value)->contains(fn ($item) => ! is_scalar($item))) {
                    $validator->errors()->add('normalized_value', 'List values must be scalar.');
                }
            }
            if (! $isListOperator && ! is_scalar($value)) {
                $validator->errors()->add('normalized_value', 'This operator requires a scalar value.');
            }
        });
    }
}
