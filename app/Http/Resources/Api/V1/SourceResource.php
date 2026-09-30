<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'scholarship_id' => $this->scholarship_id, 'university_id' => $this->university_id, 'source_type' => $this->source_type, 'source_name' => $this->source_name, 'source_url' => $this->source_url, 'is_primary' => $this->is_primary, 'status' => $this->status, 'notes' => $this->notes];
    }
}
