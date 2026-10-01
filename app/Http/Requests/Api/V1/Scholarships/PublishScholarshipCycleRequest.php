<?php

namespace App\Http\Requests\Api\V1\Scholarships;

use Illuminate\Foundation\Http\FormRequest;

class PublishScholarshipCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('scholarships.publish');
    }

    public function rules(): array
    {
        return [
            'version_id' => ['required', 'integer', 'exists:scholarship_versions,id'],
            'reason' => ['nullable', 'string', 'max:10000'],
            'published_version_id' => ['prohibited'],
            'published_at' => ['prohibited'],
            'actor_id' => ['prohibited'],
        ];
    }
}
