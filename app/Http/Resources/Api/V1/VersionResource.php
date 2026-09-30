<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'scholarship_id' => $this->scholarship_id, 'cycle_id' => $this->cycle_id, 'version_number' => $this->version_number, 'snapshot' => $this->snapshot, 'change_type' => $this->change_type, 'origin_type' => $this->origin_type, 'actor_id' => $this->actor_id, 'source_id' => $this->source_id, 'created_at' => $this->created_at?->toISOString()];
    }
}
