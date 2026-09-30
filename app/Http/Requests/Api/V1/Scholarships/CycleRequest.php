<?php

namespace App\Http\Requests\Api\V1\Scholarships;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('admin.access');
    }

    public function rules(): array
    {
        $scholarshipId = $this->route('scholarship')?->id ?? $this->route('cycle')?->scholarship_id;
        $cycle = $this->route('cycle');

        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return ['cycle_key' => [$required, 'string', 'max:80', Rule::unique('scholarship_cycles')->where('scholarship_id', $scholarshipId)->ignore($cycle?->id)], 'label' => [$required, 'string', 'max:160'], 'opening_date' => ['sometimes', 'nullable', 'date'], 'deadline' => ['sometimes', 'nullable', 'date', 'after_or_equal:opening_date'], 'application_url' => ['sometimes', 'nullable', 'url', 'max:2048'], 'status' => ['sometimes', Rule::in(['draft', 'open', 'closed', 'expired', 'archived'])]];
    }
}
