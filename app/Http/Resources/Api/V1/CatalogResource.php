<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'status' => $this->status];
        if (isset($this->normalized_name)) {
            $data['normalized_name'] = $this->normalized_name;
        }
        if (isset($this->iso2)) {
            $data += ['iso2' => $this->iso2, 'iso3' => $this->iso3];
        }
        if (isset($this->region_id)) {
            $data['region'] = new self($this->whenLoaded('region'));
        }
        if (isset($this->country_id)) {
            $data['country'] = new self($this->whenLoaded('country'));
        }
        if (isset($this->official_url)) {
            $data += ['official_url' => $this->official_url, 'description' => $this->description];
        }
        if (isset($this->level)) {
            $data['level'] = $this->level;
        }

        return $data;
    }
}
