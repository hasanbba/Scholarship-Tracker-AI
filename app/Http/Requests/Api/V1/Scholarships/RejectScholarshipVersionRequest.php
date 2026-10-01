<?php

namespace App\Http\Requests\Api\V1\Scholarships;

use Illuminate\Foundation\Http\FormRequest;

class RejectScholarshipVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('scholarships.verify');
    }

    public function rules(): array
    {
        return [
            'source_id' => ['nullable', 'integer', 'exists:scholarship_sources,id'],
            'notes' => ['required', 'string', 'max:10000'],
            'version_id' => ['prohibited'],
            'actor_id' => ['prohibited'],
            'verified_at' => ['prohibited'],
            'verified_by' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
