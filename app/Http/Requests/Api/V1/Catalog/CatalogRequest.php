<?php

namespace App\Http\Requests\Api\V1\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class CatalogRequest extends FormRequest
{
    protected function requiredOnCreate(): string
    {
        return $this->isMethod('POST') ? 'required' : 'sometimes';
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('admin.access');
    }

    protected function statusRules(): array
    {
        return ['sometimes', Rule::in(['active', 'inactive'])];
    }
}
