<?php

namespace App\Services\DataQuality;

use App\Models\ProcessingRun;
use App\Models\RawObservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ObservationProcessor
{
    public function __construct(
        private readonly ObservationIngestionService $observations,
        private readonly JsonCandidateExtractor $extractor,
        private readonly NormalizationService $normalizer,
        private readonly CandidateValidationService $validator,
    ) {}

    public function process(RawObservation $observation, string $runKey, string $parserVersion = 'json-v1'): ProcessingRun
    {
        if ($observation->source_access_status !== 'success') {
            throw ValidationException::withMessages(['observation' => 'Only successful source captures can be processed as scholarship evidence.']);
        }

        return DB::transaction(function () use ($observation, $runKey, $parserVersion): ProcessingRun {
            $run = ProcessingRun::query()->firstOrCreate(
                ['observation_id' => $observation->id, 'run_key' => $runKey],
                [
                    'parser_name' => 'structured-json',
                    'parser_version' => $parserVersion,
                    'normalization_version' => NormalizationService::VERSION,
                    'validation_version' => '1',
                    'status' => 'queued',
                    'attempt_count' => 0,
                ],
            );
            $run = ProcessingRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->parser_version !== $parserVersion) {
                throw new ConflictHttpException('A new parser version requires a distinct processing run key.');
            }
            if (in_array($run->status, ['succeeded', 'invalid', 'failed'], true)) {
                return $run;
            }

            $run->forceFill([
                'status' => 'running',
                'attempt_count' => $run->attempt_count + 1,
                'started_at' => $run->started_at ?? now(),
            ])->save();

            try {
                $raw = $this->observations->payload($observation);
                $extracted = $this->extractor->extract($raw);
                $normalized = $this->normalizer->normalize($extracted);
                $errors = $this->validator->validate($normalized);
                $warnings = [];
                if (! array_key_exists('funding', $extracted)) {
                    $warnings[] = ['field' => 'funding', 'code' => 'funding_not_stated'];
                }
                if (! array_key_exists('eligibility_rules', $extracted) || $normalized['eligibility_rules'] === []) {
                    $warnings[] = ['field' => 'eligibility_rules', 'code' => 'eligibility_not_stated'];
                }
                if ($normalized['cycle']['deadline'] === null) {
                    $warnings[] = ['field' => 'cycle.deadline', 'code' => 'deadline_unknown'];
                }
                $run->forceFill([
                    'extracted_payload' => ['candidate' => $extracted, 'evidence' => $this->evidencePointers($extracted)],
                    'normalized_payload' => $normalized,
                    'validation_errors' => $errors,
                    'validation_warnings' => $warnings,
                    'status' => $errors === [] ? 'succeeded' : 'invalid',
                    'last_error_code' => $errors === [] ? null : 'candidate_validation_failed',
                    'finished_at' => now(),
                ])->save();
            } catch (\Throwable $exception) {
                $run->forceFill([
                    'status' => $exception instanceof JsonException ? 'invalid' : 'failed',
                    'last_error_code' => $exception instanceof JsonException ? 'invalid_json' : 'processing_failed',
                    'validation_errors' => [['field' => 'observation', 'code' => $exception instanceof JsonException ? 'invalid_json' : 'processing_failed']],
                    'finished_at' => now(),
                ])->save();
            }

            return $run->refresh();
        });
    }

    private function evidencePointers(array $value, string $path = ''): array
    {
        $pointers = [];
        foreach ($value as $key => $item) {
            $child = $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            if (is_array($item) && ! array_is_list($item)) {
                $pointers += $this->evidencePointers($item, $child);
            } else {
                $pointers[$child] = ['observation_payload_pointer' => $child];
            }
        }

        return $pointers;
    }
}
