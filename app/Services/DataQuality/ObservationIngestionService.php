<?php

namespace App\Services\DataQuality;

use App\Models\RawObservation;
use App\Models\ScholarshipSource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ObservationIngestionService
{
    public function ingest(
        ScholarshipSource $source,
        string $observedUrl,
        string $rawPayload,
        string $idempotencyKey,
        string $producerType = 'internal',
        ?string $producerRef = null,
        string $accessStatus = 'success',
        ?string $contentType = 'application/json',
        ?\DateTimeInterface $observedAt = null,
    ): RawObservation {
        $source->loadMissing('scholarship', 'university');
        abort_unless($source->status === 'active', 422, 'The evidence source is inactive.');
        $this->assertUrlBelongsToSource($source, $observedUrl);

        $hash = hash('sha256', $rawPayload);
        $canonicalUrl = $this->canonicalComparisonUrl($observedUrl);
        $urlHash = hash('sha256', $canonicalUrl);

        $existing = RawObservation::query()
            ->where('source_id', $source->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            $this->assertSameReceipt($existing, $hash, $observedUrl, $urlHash);

            return $existing;
        }

        $artifactRef = 'evidence/sha256/'.substr($hash, 0, 2).'/'.$hash;
        $disk = Storage::disk('local');
        if (! $disk->exists($artifactRef)) {
            if (! $disk->put($artifactRef, $rawPayload) && ! $disk->exists($artifactRef)) {
                throw new \RuntimeException('The protected evidence artifact could not be stored.');
            }
        }

        try {
            return DB::transaction(fn () => RawObservation::query()->create([
                'source_id' => $source->id,
                'observed_url' => $observedUrl,
                'observed_url_hash' => $urlHash,
                'observed_at' => $observedAt ?? now(),
                'content_hash' => $hash,
                'artifact_ref' => $artifactRef,
                'content_type' => $contentType,
                'content_length' => strlen($rawPayload),
                'source_access_status' => $accessStatus,
                'producer_type' => $producerType,
                'producer_ref' => $producerRef,
                'idempotency_key' => $idempotencyKey,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            $existing = RawObservation::query()
                ->where('source_id', $source->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->assertSameReceipt($existing, $hash, $observedUrl, $urlHash);

            return $existing;
        }
    }

    public function payload(RawObservation $observation): string
    {
        $disk = Storage::disk('local');
        $payload = $disk->get($observation->artifact_ref);
        if (! is_string($payload) || ! hash_equals($observation->content_hash, hash('sha256', $payload))) {
            throw ValidationException::withMessages(['artifact' => 'The protected observation artifact is missing or failed its integrity check.']);
        }

        return $payload;
    }

    public function canonicalComparisonUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw ValidationException::withMessages(['observed_url' => 'A valid HTTP or HTTPS URL is required.']);
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $authority = $host;
        if ($port !== null && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $authority .= ':'.$port;
        }

        return $scheme.'://'.$authority.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function assertUrlBelongsToSource(ScholarshipSource $source, string $url): void
    {
        $requested = parse_url($url);
        $registered = parse_url($source->source_url);
        if (! is_array($requested) || ! is_array($registered) || ! isset($requested['host'], $registered['host'])) {
            throw ValidationException::withMessages(['observed_url' => 'The observed URL must be valid and match the registered source.']);
        }

        $observedHost = strtolower($requested['host']);
        $sourceHost = strtolower($registered['host']);
        if ($observedHost !== $sourceHost && ! str_ends_with($observedHost, '.'.$sourceHost)) {
            throw ValidationException::withMessages(['observed_url' => 'The observed URL is outside the registered source host.']);
        }
    }

    private function assertSameReceipt(RawObservation $observation, string $hash, string $url, string $urlHash): void
    {
        if (! hash_equals($observation->content_hash, $hash)
            || $observation->observed_url !== $url
            || ! hash_equals($observation->observed_url_hash, $urlHash)) {
            throw new ConflictHttpException('The idempotency key was already used for different observation content.');
        }
    }
}
