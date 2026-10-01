<?php

namespace App\Services\Crawler;

use App\Models\CrawlerWorker;
use App\Models\CrawlerWorkerActivationCode;
use App\Models\CrawlerWorkerCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class WorkerActivationService
{
    public function issueCode(string $label, User $user): array
    {
        $plain = bin2hex(random_bytes(32));
        $record = CrawlerWorkerActivationCode::query()->create([
            'code_hash' => hash('sha256', $plain), 'worker_label' => $label,
            'issued_by_user_id' => $user->id,
            'expires_at' => now()->addMinutes(config('crawler.activation_ttl_minutes')),
        ]);
        app(CrawlerEventRecorder::class)->record('activation_code_issued', user: $user, details: ['activation_code_id' => $record->id, 'expires_at' => $record->expires_at->toIso8601String()]);

        return ['id' => $record->id, 'activation_code' => $plain, 'expires_at' => $record->expires_at];
    }

    public function activate(string $code, ?string $version, int $protocol): array
    {
        return DB::transaction(function () use ($code, $version, $protocol): array {
            $activation = CrawlerWorkerActivationCode::query()->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            if (! $activation || $activation->consumed_at || $activation->revoked_at || $activation->expires_at->isPast()) {
                throw new UnauthorizedHttpException('', 'Activation code is invalid or expired.');
            }
            $worker = CrawlerWorker::query()->create([
                'uuid' => (string) Str::uuid(), 'label' => $activation->worker_label,
                'software_version' => $version, 'protocol_version' => $protocol,
                'status' => 'active', 'last_heartbeat_at' => now(), 'activated_at' => now(),
            ]);
            $plainToken = bin2hex(random_bytes(32));
            $credential = CrawlerWorkerCredential::query()->create([
                'worker_id' => $worker->id, 'token_hash' => hash('sha256', $plainToken),
                'scopes' => ['workers.me', 'jobs.claim', 'jobs.heartbeat', 'jobs.results'],
                'expires_at' => now()->addDays(config('crawler.credential_ttl_days')),
            ]);
            $activation->forceFill(['worker_id' => $worker->id, 'consumed_at' => now()])->save();
            app(CrawlerEventRecorder::class)->record('worker_activated', worker: $worker, details: ['protocol_version' => $protocol]);
            app(CrawlerEventRecorder::class)->record('credential_issued', worker: $worker, details: ['credential_id' => $credential->id, 'reason' => 'activation']);

            return ['worker' => $worker, 'token' => $plainToken, 'expires_at' => $credential->expires_at];
        });
    }

    public function rotate(CrawlerWorker $worker, User $user): array
    {
        return DB::transaction(function () use ($worker, $user): array {
            $current = $worker->credentials()->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first();
            $now = now();
            $current?->forceFill(['revoked_at' => $now])->save();
            $plain = bin2hex(random_bytes(32));
            $credential = $worker->credentials()->create([
                'token_hash' => hash('sha256', $plain), 'scopes' => ['workers.me', 'jobs.claim', 'jobs.heartbeat', 'jobs.results'],
                'issued_by_user_id' => $user->id, 'rotated_from_id' => $current?->id,
                'expires_at' => $now->copy()->addDays(config('crawler.credential_ttl_days')),
            ]);
            app(CrawlerEventRecorder::class)->record('credential_rotated', worker: $worker, user: $user, details: ['credential_id' => $credential->id, 'revoked_credential_id' => $current?->id]);

            return ['token' => $plain, 'expires_at' => $credential->expires_at];
        });
    }
}
