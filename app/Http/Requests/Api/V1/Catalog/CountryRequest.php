<?php

namespace App\Http\Requests\Api\V1\Catalog;

use Illuminate\Validation\Rule;

class CountryRequest extends CatalogRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        if ($this->has('name')) {
            $normalized['normalized_name'] = mb_strtolower(trim($this->string('name')->toString()));
        }
        if ($this->filled('iso2')) {
            $normalized['iso2'] = strtoupper((string) $this->input('iso2'));
        }
        if ($this->filled('iso3')) {
            $normalized['iso3'] = strtoupper((string) $this->input('iso3'));
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        $id = $this->route('country')?->id;

        $rules = ['name' => [$this->requiredOnCreate(), 'string', 'max:160'], 'slug' => [$this->requiredOnCreate(), 'alpha_dash', 'max:180', Rule::unique('countries')->ignore($id)], 'region_id' => ['sometimes', 'nullable', 'exists:regions,id'], 'iso2' => ['sometimes', 'nullable', 'alpha', 'size:2', Rule::unique('countries')->ignore($id)], 'iso3' => ['sometimes', 'nullable', 'alpha', 'size:3', Rule::unique('countries')->ignore($id)], 'status' => $this->statusRules()];
        if ($this->has('name')) {
            $rules['normalized_name'] = ['required', 'string', 'max:180', Rule::unique('countries', 'normalized_name')->ignore($id)];
        }

        return $rules;
    }
}
