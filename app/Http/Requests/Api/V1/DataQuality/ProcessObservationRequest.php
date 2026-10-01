<?php

namespace App\Http\Requests\Api\V1\DataQuality;

use Illuminate\Foundation\Http\FormRequest;

class ProcessObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('scholarships.data-quality.manage');
    }

    public function rules(): array
    {
        return ['run_key' => ['required', 'string', 'max:191'], 'parser_version' => ['sometimes', 'string', 'max:80']];
    }
}
