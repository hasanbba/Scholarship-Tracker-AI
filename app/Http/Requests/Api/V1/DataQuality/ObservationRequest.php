<?php

namespace App\Http\Requests\Api\V1\DataQuality;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('scholarships.data-quality.manage');
    }

    public function rules(): array
    {
        return [
            'source_id' => ['required', 'integer', 'exists:scholarship_sources,id'],
            'observed_url' => ['required', 'url', 'max:2048'],
            'raw_payload' => ['required', 'string', 'max:4194304'],
            'content_type' => ['nullable', 'string', 'max:160'],
            'source_access_status' => ['sometimes', Rule::in(['success', 'blocked', 'not_found', 'rate_limited', 'error', 'unknown'])],
            'producer_type' => ['sometimes', 'string', 'max:32'],
            'producer_ref' => ['nullable', 'string', 'max:191'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ];
    }
}
