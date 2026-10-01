<?php

namespace App\Http\Requests\Api\V1\Discovery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScholarshipSearchRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $keyword = trim((string) $this->input('q', ''));
        $this->merge(['q' => $keyword === '' ? null : $keyword]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $allowed = array_keys($this->rules());
            foreach (array_diff(array_keys($this->query()), $allowed) as $unknown) {
                $validator->errors()->add($unknown, 'This query parameter is not supported.');
            }

            $from = $this->input('deadline_from');
            $to = $this->input('deadline_to');
            if ($from && $to && $to < $from) {
                $validator->errors()->add('deadline_to', 'The deadline to must be on or after the deadline from.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'country' => ['nullable', 'string', 'max:180', Rule::exists('countries', 'slug')->where('status', 'active')],
            'region' => ['nullable', 'string', 'max:180', Rule::exists('regions', 'slug')->where('status', 'active')],
            'university' => ['nullable', 'string', 'max:220', Rule::exists('universities', 'slug')->where('status', 'active')],
            'subject' => ['nullable', 'string', 'max:180', Rule::exists('subjects', 'slug')->where('status', 'active')],
            'degree' => ['nullable', 'string', 'max:140', Rule::exists('degrees', 'slug')->where('status', 'active')],
            'deadline_from' => ['nullable', 'date_format:Y-m-d'],
            'deadline_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:deadline_from'],
            'funding_classification' => ['nullable', Rule::in(['fully_funded', 'partially_funded', 'tuition_only', 'stipend_only', 'mixed', 'unknown'])],
            'cycle' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort' => ['nullable', Rule::in(['deadline_asc', 'deadline_desc', 'newest', 'title_asc', 'title_desc'])],
        ];
    }
}
