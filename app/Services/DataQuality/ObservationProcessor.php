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
        private readonly HtmlScholarshipExtractor $htmlExtractor,
        private readonly NormalizationService $normalizer,
        private readonly CandidateValidationService $validator,
    ) {}

    public function process(RawObservation $observation, string $runKey, ?string $parserVersion = null): ProcessingRun
    {
        if ($observation->source_access_status !== 'success') {
            throw ValidationException::withMessages(['observation' => 'Only successful source captures can be processed as scholarship evidence.']);
        }

        $parserVersion ??= match ($observation->content_type) {
            'text/html', 'application/xhtml+xml' => HtmlScholarshipExtractor::VERSION,
            'application/pdf' => 'unsupported-v1',
            default => 'json-v1',
        };

        return DB::transaction(function () use ($observation, $runKey, $parserVersion): ProcessingRun {
            $run = ProcessingRun::query()->firstOrCreate(
                ['observation_id' => $observation->id, 'run_key' => $runKey],
                [
                    'parser_name' => $parserVersion === HtmlScholarshipExtractor::VERSION ? 'deterministic-html-scholarship' : 'structured-json',
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
                if ($observation->content_type === 'application/pdf') {
                    $run->forceFill(['parser_name' => 'unsupported', 'status' => 'failed', 'last_error_code' => 'unsupported_parser',
                        'validation_errors' => [['field' => 'observation', 'code' => 'unsupported_pdf_parser']], 'finished_at' => now()])->save();

                    return $run->refresh();
                }
                $phase6 = $parserVersion === HtmlScholarshipExtractor::VERSION
                    ? $this->htmlExtractor->extract($raw, $observation->loadMissing('source.university.country.region', 'source.scholarship'))
                    : null;
                $extracted = $phase6 ? $phase6['candidate'] : $this->extractor->extract($raw);
                $normalized = $this->normalizer->normalize($extracted);
                $errors = $this->validator->validate($normalized);
                $warnings = [];
                if ($phase6 !== null) {
                    foreach ($phase6['fields'] as &$field) {
                        if (is_array($field['evidence'] ?? null)) {
                            $field['evidence'] += ['source_url' => $observation->observed_url, 'observation_id' => $observation->id,
                                'processing_run_id' => $run->id, 'parser_version' => $parserVersion, 'extraction_method' => $field['method'], 'extracted_at' => now()->toIso8601String()];
                        }
                        if (($field['status'] ?? null) === 'invalid') $errors[] = ['field' => $field['path'], 'code' => $field['issue'] ?? 'invalid_extracted_value'];
                    }
                    unset($field);
                    foreach ($phase6['issues'] as $issue) $warnings[] = $issue;
                    $normalized['_phase6'] = ['fields' => $phase6['fields'], 'issues' => $phase6['issues'], 'source_url' => $observation->observed_url,
                        'observation_id' => $observation->id, 'processing_run_id' => $run->id, 'parser_version' => $parserVersion];
                }
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
                    'parser_name' => $phase6 !== null ? 'deterministic-html-scholarship' : 'structured-json',
                    'extracted_payload' => ['candidate' => $extracted, 'evidence' => $phase6 !== null ? $this->phase6Evidence($phase6['fields']) : $this->evidencePointers($extracted),
                        'phase6' => $phase6 ? ['fields' => $phase6['fields'], 'issues' => $phase6['issues'], 'source_url' => $observation->observed_url,
                            'observation_id' => $observation->id, 'processing_run_id' => $run->id, 'parser_version' => $parserVersion] : null],
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

    private function phase6Evidence(array $fields): array
    {
        $evidence = [];
        foreach ($fields as $field) if (is_array($field['evidence'] ?? null)) $evidence['/'.str_replace('.', '/', $field['path'])] = $field['evidence'];
        return $evidence;
    }
}
