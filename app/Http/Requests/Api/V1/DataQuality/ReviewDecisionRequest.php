<?php

namespace App\Http\Requests\Api\V1\DataQuality;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->hasPermission('scholarships.review.manage')
            || $this->user()?->hasPermission('scholarships.changes.approve'));
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['claim', 'assign', 'unassign', 'approve', 'reject', 'needs_evidence', 'cancel'])],
            'request_key' => ['required', 'string', 'max:191'],
            'reason' => ['nullable', 'string', 'max:10000'],
            'evidence_refs' => ['sometimes', 'array', 'max:50'],
            'evidence_refs.*' => ['string', 'max:1024'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
