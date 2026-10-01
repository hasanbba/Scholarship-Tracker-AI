<?php

namespace App\Services\Crawler;

use App\Models\CrawlAttempt;
use App\Models\CrawlerEvent;
use App\Models\CrawlerWorker;
use App\Models\CrawlJob;
use App\Models\ScholarshipSource;
use App\Models\User;

class CrawlerEventRecorder
{
    public function record(string $type, ?ScholarshipSource $source = null, ?CrawlJob $job = null, ?CrawlAttempt $attempt = null, ?CrawlerWorker $worker = null, ?User $user = null, array $details = []): CrawlerEvent
    {
        return CrawlerEvent::query()->create([
            'event_type' => $type, 'source_id' => $source?->id, 'job_id' => $job?->id,
            'attempt_id' => $attempt?->id, 'worker_id' => $worker?->id, 'actor_user_id' => $user?->id,
            'details' => $details, 'occurred_at' => now(),
        ]);
    }
}
