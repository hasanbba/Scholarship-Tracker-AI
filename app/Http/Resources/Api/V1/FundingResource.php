<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'cycle_id' => $this->cycle_id, 'classification' => $this->classification, 'notes' => $this->notes, 'verification_status' => $this->verification_status];
        foreach (['tuition', 'stipend', 'accommodation', 'health_insurance', 'travel', 'visa', 'research_grant', 'application_fee', 'other_benefits'] as $benefit) {
            $data[$benefit] = ['amount' => $this->{$benefit.'_amount'}, 'currency' => $this->{$benefit.'_currency'}, 'period' => $this->{$benefit.'_period'}];
        }

        return $data;
    }
}
