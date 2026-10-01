<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'scholarship_id' => $this->scholarship_id, 'cycle_id' => $this->cycle_id, 'version_number' => $this->version_number, 'snapshot' => $this->snapshot, 'change_type' => $this->change_type, 'origin_type' => $this->origin_type, 'actor_id' => $this->actor_id, 'source_id' => $this->source_id, 'created_at' => $this->created_at?->toISOString()];

        if ($this->resource->relationLoaded('verificationRecords')) {
            $records = $this->verificationRecords;
            $data['verification_status'] = $records->first()?->status ?? 'pending';
            $data['verification_records'] = VerificationRecordResource::collection($records);
        }

        return $data;
    }
}
