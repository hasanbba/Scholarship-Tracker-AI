<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScholarshipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'slug' => $this->slug, 'description' => $this->description, 'official_url' => $this->official_url, 'publication_status' => $this->publication_status, 'lifecycle_status' => $this->lifecycle_status, 'university' => new CatalogResource($this->whenLoaded('university')), 'subjects' => CatalogResource::collection($this->whenLoaded('subjects')), 'cycles' => CycleResource::collection($this->whenLoaded('cycles')), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
