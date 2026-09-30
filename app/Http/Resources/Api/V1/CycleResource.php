<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'scholarship_id' => $this->scholarship_id, 'cycle_key' => $this->cycle_key, 'label' => $this->label, 'opening_date' => $this->opening_date?->toDateString(), 'deadline' => $this->deadline?->toDateString(), 'application_url' => $this->application_url, 'status' => $this->status, 'funding' => new FundingResource($this->whenLoaded('funding')), 'eligibility_rules' => EligibilityResource::collection($this->whenLoaded('eligibilityRules')), 'versions_count' => $this->whenCounted('versions')];
    }
}
