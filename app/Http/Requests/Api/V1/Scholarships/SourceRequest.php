<?php

namespace App\Http\Requests\Api\V1\Scholarships;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('admin.access');
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('source_url')) {
            return;
        }
        $parts = parse_url(trim($this->input('source_url')));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return;
        }
        $canonical = strtolower($parts['scheme']).'://'.strtolower($parts['host']);
        if (isset($parts['port'])) {
            $canonical .= ':'.$parts['port'];
        }
        $canonical .= rtrim($parts['path'] ?? '', '/') ?: '/';
        if (isset($parts['query'])) {
            $canonical .= '?'.$parts['query'];
        }
        $this->merge(['source_url' => $canonical, 'source_url_hash' => hash('sha256', $canonical)]);
    }

    public function rules(): array
    {
        $scholarshipId = $this->route('scholarship')?->id;

        return ['university_id' => ['nullable', 'exists:universities,id'], 'source_type' => ['required', Rule::in(['university_page', 'graduate_school', 'faculty_page', 'official_pdf', 'government', 'official_agency', 'other_official'])], 'source_name' => ['required', 'string', 'max:200'], 'source_url' => ['required', 'url', 'max:2048', Rule::unique('scholarship_sources')->where('scholarship_id', $scholarshipId)], 'source_url_hash' => ['required', 'string', 'size:64'], 'is_primary' => ['sometimes', 'boolean'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])], 'notes' => ['nullable', 'string', 'max:10000']];
    }
}
