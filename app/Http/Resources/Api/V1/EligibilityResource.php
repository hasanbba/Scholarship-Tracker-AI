<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EligibilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'cycle_id' => $this->cycle_id, 'rule_type' => $this->rule_type, 'operator' => $this->operator, 'normalized_value' => $this->normalized_value, 'unit' => $this->unit, 'display_text' => $this->display_text, 'degree_id' => $this->degree_id, 'subject_id' => $this->subject_id, 'country_id' => $this->country_id, 'metadata' => $this->metadata];
    }
}
