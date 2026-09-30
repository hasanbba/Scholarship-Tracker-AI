<?php

namespace App\Http\Requests\Api\V1\Catalog;

use Illuminate\Validation\Rule;

class SubjectRequest extends CatalogRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['normalized_name' => mb_strtolower(trim($this->string('name')->toString()))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('subject')?->id;

        $rules = ['name' => [$this->requiredOnCreate(), 'string', 'max:160'], 'slug' => [$this->requiredOnCreate(), 'alpha_dash', 'max:180', Rule::unique('subjects')->ignore($id)], 'status' => $this->statusRules()];
        if ($this->has('name')) {
            $rules['normalized_name'] = ['required', 'string', 'max:180', Rule::unique('subjects', 'normalized_name')->ignore($id)];
        }

        return $rules;
    }
}
