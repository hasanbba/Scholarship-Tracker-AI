<?php

namespace App\Http\Requests\Api\V1\Scholarships;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FundingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['tuition', 'stipend', 'accommodation', 'health_insurance', 'travel', 'visa', 'research_grant', 'application_fee', 'other_benefits'] as $benefit) {
            $key = $benefit.'_currency';
            if ($this->filled($key)) {
                $normalized[$key] = strtoupper((string) $this->input($key));
            }
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('admin.access');
    }

    public function rules(): array
    {
        $rules = ['classification' => ['required', Rule::in(['fully_funded', 'partially_funded', 'tuition_only', 'stipend_only', 'mixed', 'unknown'])], 'notes' => ['sometimes', 'nullable', 'string', 'max:10000'], 'verification_status' => ['sometimes', Rule::in(['unverified'])]];
        foreach (['tuition', 'stipend', 'accommodation', 'health_insurance', 'travel', 'visa', 'research_grant', 'application_fee', 'other_benefits'] as $benefit) {
            $rules[$benefit.'_amount'] = ['nullable', 'numeric', 'min:0', 'decimal:0,2'];
            $rules[$benefit.'_currency'] = ['nullable', 'required_with:'.$benefit.'_amount', 'alpha', 'size:3'];
            $rules[$benefit.'_period'] = ['nullable', 'string', 'max:40'];
        }

        return $rules;
    }
}
