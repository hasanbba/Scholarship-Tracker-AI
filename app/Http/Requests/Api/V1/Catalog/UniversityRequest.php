<?php

namespace App\Http\Requests\Api\V1\Catalog;

use Illuminate\Validation\Rule;

class UniversityRequest extends CatalogRequest
{
    protected function prepareForValidation(): void
    {
        $name = $this->input('name', $this->route('university')?->name);
        if (is_string($name) && ($this->has('name') || $this->has('country_id'))) {
            $this->merge(['normalized_name' => mb_strtolower(trim($name))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('university')?->id;

        $countryId = $this->input('country_id', $this->route('university')?->country_id);

        $rules = ['country_id' => [$this->requiredOnCreate(), 'exists:countries,id'], 'name' => [$this->requiredOnCreate(), 'string', 'max:200'], 'slug' => [$this->requiredOnCreate(), 'alpha_dash', 'max:220', Rule::unique('universities')->ignore($id)], 'official_url' => ['sometimes', 'nullable', 'url', 'max:2048'], 'description' => ['sometimes', 'nullable', 'string', 'max:10000'], 'status' => $this->statusRules()];
        if ($this->has('name') || $this->has('country_id')) {
            $rules['normalized_name'] = ['required', 'string', 'max:220', Rule::unique('universities', 'normalized_name')->where('country_id', $countryId)->ignore($id)];
        }

        return $rules;
    }
}
