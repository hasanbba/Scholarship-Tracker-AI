<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'scholarship_id' => $this->scholarship_id, 'university_id' => $this->university_id, 'source_type' => $this->source_type, 'source_name' => $this->source_name, 'source_url' => $this->source_url, 'is_primary' => $this->is_primary, 'status' => $this->status, 'notes' => $this->notes, 'crawl_enabled' => $this->crawl_enabled, 'crawl_method' => $this->crawl_method, 'crawl_frequency' => $this->crawl_frequency, 'crawl_priority' => $this->crawl_priority, 'allowed_path_prefix' => $this->allowed_path_prefix, 'robots_policy' => $this->robots_policy, 'source_concurrency_limit' => $this->source_concurrency_limit];
    }
}
