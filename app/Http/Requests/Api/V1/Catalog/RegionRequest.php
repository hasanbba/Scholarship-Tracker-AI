<?php

namespace App\Http\Requests\Api\V1\Catalog;

use Illuminate\Validation\Rule;

class RegionRequest extends CatalogRequest
{
    public function rules(): array
    {
        $id = $this->route('region')?->id;

        return ['name' => [$this->requiredOnCreate(), 'string', 'max:160'], 'slug' => [$this->requiredOnCreate(), 'alpha_dash', 'max:180', Rule::unique('regions')->ignore($id)], 'status' => $this->statusRules()];
    }
}
