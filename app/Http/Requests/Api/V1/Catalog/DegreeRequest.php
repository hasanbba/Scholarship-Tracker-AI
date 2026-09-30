<?php

namespace App\Http\Requests\Api\V1\Catalog;

use Illuminate\Validation\Rule;

class DegreeRequest extends CatalogRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['normalized_name' => mb_strtolower(trim($this->string('name')->toString()))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('degree')?->id;

        $rules = ['name' => [$this->requiredOnCreate(), 'string', 'max:120'], 'slug' => [$this->requiredOnCreate(), 'alpha_dash', 'max:140', Rule::unique('degrees')->ignore($id)], 'level' => ['sometimes', 'nullable', 'string', 'max:40'], 'status' => $this->statusRules()];
        if ($this->has('name')) {
            $rules['normalized_name'] = ['required', 'string', 'max:140', Rule::unique('degrees', 'normalized_name')->ignore($id)];
        }

        return $rules;
    }
}
