<?php

namespace App\Http\Requests\Api\V1\Scholarships;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScholarshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('admin.access');
    }

    public function rules(): array
    {
        $id = $this->route('scholarship')?->id;

        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return ['university_id' => [$required, 'exists:universities,id'], 'title' => [$required, 'string', 'max:220'], 'slug' => [$required, 'alpha_dash', 'max:240', Rule::unique('scholarships')->ignore($id)], 'description' => ['sometimes', 'nullable', 'string', 'max:20000'], 'official_url' => ['sometimes', 'nullable', 'url', 'max:2048'], 'publication_status' => ['sometimes', Rule::in(['draft'])], 'lifecycle_status' => ['sometimes', Rule::in(['active', 'expired', 'archived'])], 'subject_ids' => ['sometimes', 'array'], 'subject_ids.*' => ['integer', 'distinct', 'exists:subjects,id']];
    }
}
