<?php

namespace App\Http\Requests\Api\V1\Scholarships;

use Illuminate\Foundation\Http\FormRequest;

class UnpublishScholarshipCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('scholarships.publish');
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:10000'],
            'published_version_id' => ['prohibited'],
            'published_at' => ['prohibited'],
            'actor_id' => ['prohibited'],
        ];
    }
}
