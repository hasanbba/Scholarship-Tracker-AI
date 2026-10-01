<?php

namespace App\Services\DataQuality;

use App\Models\DuplicateCandidate;
use App\Models\ProcessingRun;
use App\Models\ProposedChange;
use App\Models\ReviewTask;
use App\Models\Scholarship;
use App\Models\ScholarshipCycle;
use App\Models\ScholarshipVersion;
use Illuminate\Support\Facades\DB;

class ChangeDetectionService
{
    public function detect(ProcessingRun $run): ?ReviewTask
    {
        $run->loadMissing('observation.source');
        abort_unless($run->status === 'succeeded', 422, 'Only a successfully validated processing run can be detected.');
        $incoming = $run->normalized_payload;
        $observation = $run->observation;

        $repeatedContent = $observation->newQuery()->where('source_id', $observation->source_id)
            ->where('content_hash', $observation->content_hash)->whereKeyNot($observation->id)->exists();
        if ($repeatedContent) {
            $sameCandidate = $this->findExactProgram($incoming);
            if ($sameCandidate !== null) {
                $sameCycle = $sameCandidate->cycles()->where('cycle_key', $incoming['cycle']['cycle_key'])->first();
                $baseline = $sameCycle?->versions()->orderByDesc('version_number')->first();
                if ($baseline !== null && $this->matchesBaseline($incoming, $baseline)) {
                    return null;
                }
            }
        }

        return DB::transaction(function () use ($run, $incoming): ?ReviewTask {
            $run = ProcessingRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->proposedChanges()->exists() || $run->duplicateCandidates()->exists()) {
                return ReviewTask::query()->where('processing_run_id', $run->id)->whereIn('state', ['pending', 'in_review'])->first();
            }

            $program = $this->findExactProgram($incoming);
            $cycle = $program?->cycles()->where('cycle_key', $incoming['cycle']['cycle_key'])->first();
            $candidate = null;
            $baseline = $cycle?->versions()->orderByDesc('version_number')->first();
            $knownSources = collect($baseline?->snapshot['sources'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);
            $newEvidenceSource = $program !== null
                && ((int) ($run->observation->source->scholarship_id ?? 0) !== (int) $program->id
                    || ($cycle !== null && ! $knownSources->contains((int) $run->observation->source_id)));
            if ($program !== null && $newEvidenceSource) {
                $signals = $this->signals($incoming, $program, $cycle);
                $candidate = DuplicateCandidate::query()->firstOrCreate([
                    'processing_run_id' => $run->id,
                    'candidate_scholarship_id' => $program->id,
                    'candidate_cycle_id' => null,
                ], [
                    'classification' => 'program_identity',
                    'state' => 'possible',
                    'signals' => $signals,
                ]);
            }

            $taskType = $program === null ? 'new_record' : ($candidate !== null ? 'duplicate_resolution' : ($cycle === null ? 'new_record' : 'change_review'));
            $paths = $this->candidateFields($incoming, $program === null);
            $changes = [];
            foreach ($paths as $path => $value) {
                $old = $baseline ? $this->snapshotValue($baseline->snapshot, $path) : null;
                if ($baseline !== null && $this->equivalent($old, $value)) {
                    continue;
                }
                $changes[] = ProposedChange::query()->create([
                    'processing_run_id' => $run->id,
                    'raw_observation_id' => $run->observation_id,
                    'target_scholarship_id' => $program?->id,
                    'target_cycle_id' => $cycle?->id,
                    'baseline_version_id' => $baseline?->id,
                    'field_path' => $path,
                    'old_value' => $old,
                    'extracted_value' => $this->extractedValue($run->extracted_payload, $path),
                    'proposed_value' => $value,
                    'display_old' => $this->display($old),
                    'display_new' => $this->display($value),
                    'normalization_version' => $run->normalization_version,
                    'evidence_locator' => $this->evidenceLocator($run, $path),
                    'detection_reason' => $baseline ? 'normalized_value_changed' : 'first_discovery',
                    'materiality' => $this->materiality($path),
                    'status' => 'pending',
                ]);
            }

            if ($changes === [] && $candidate === null) {
                return null;
            }

            $task = ReviewTask::query()->create([
                'task_type' => $taskType,
                'target_scholarship_id' => $program?->id,
                'target_cycle_id' => $cycle?->id,
                'processing_run_id' => $run->id,
                'duplicate_candidate_id' => $candidate?->id,
                'priority' => collect($changes)->contains(fn (ProposedChange $change) => $change->materiality === 'material') ? 10 : 0,
                'state' => 'pending',
                'lock_version' => 0,
            ]);
            if ($changes !== []) {
                $task->proposals()->attach(collect($changes)->pluck('id')->all());
            }

            return $task->load('proposals', 'duplicateCandidate');
        });
    }

    private function findExactProgram(array $incoming): ?Scholarship
    {
        $identity = $incoming['scholarship'];
        $title = $this->normalizeTitle($identity['title'] ?? '');
        $url = $identity['official_url'] ?? null;
        if (! $identity['university_id'] || ($title === '' && $url === null)) {
            return null;
        }

        $programs = Scholarship::query()->where('university_id', $identity['university_id'])->with(['subjects', 'cycles.versions'])->get();
        foreach ($programs as $program) {
            if (($title !== '' && $this->normalizeTitle($program->title) === $title)
                || ($url !== null && $this->urlKey($program->official_url) === $this->urlKey($url))) {
                return $program;
            }
        }

        return null;
    }

    private function signals(array $incoming, Scholarship $program, ?ScholarshipCycle $cycle): array
    {
        $candidateTitle = $this->normalizeTitle($incoming['scholarship']['title'] ?? '');
        $title = $this->normalizeTitle($program->title);
        similar_text($candidateTitle, $title, $similarity);

        return [
            'university' => ['candidate_id' => $incoming['scholarship']['university_id'], 'matched_id' => $program->university_id, 'match' => (int) $incoming['scholarship']['university_id'] === (int) $program->university_id],
            'official_url' => ['candidate' => $incoming['scholarship']['official_url'] ?? null, 'matched' => $program->official_url, 'match' => ($incoming['scholarship']['official_url'] ?? null) !== null && $this->urlKey($incoming['scholarship']['official_url']) === $this->urlKey($program->official_url)],
            'normalized_title' => ['candidate' => $candidateTitle, 'matched' => $title, 'match' => $candidateTitle !== '' && $candidateTitle === $title],
            'text_similarity_percent' => round($similarity, 2),
            'subjects' => ['candidate_ids' => $incoming['subjects'], 'matched_ids' => $program->subjects->pluck('id')->all()],
            'cycle' => ['candidate_key' => $incoming['cycle']['cycle_key'], 'matched_id' => $cycle?->id, 'match' => $cycle !== null],
            'funding' => ['candidate' => $incoming['funding'], 'matched' => $cycle?->funding?->only(array_keys($incoming['funding']))],
            'eligibility' => ['candidate' => $incoming['eligibility_rules'], 'matched' => $cycle?->eligibilityRules()->get()->toArray()],
            'degrees' => [
                'candidate_ids' => collect($incoming['eligibility_rules'] ?? [])->pluck('degree_id')->filter()->unique()->values()->all(),
                'matched_ids' => $cycle?->eligibilityRules()->whereNotNull('degree_id')->pluck('degree_id')->unique()->values()->all() ?? [],
            ],
        ];
    }

    private function candidateFields(array $candidate, bool $newProgram): array
    {
        $fields = [];
        $provided = $candidate['provided_fields'] ?? [];
        foreach ($candidate['scholarship'] as $key => $value) {
            if (in_array($key, $provided['scholarship'] ?? [], true) || ($newProgram && $key === 'university_id')) {
                $fields['scholarship.'.$key] = $value;
            }
        }
        if ($provided['subjects'] ?? false) {
            $fields['subjects'] = $candidate['subjects'];
        }
        foreach ($candidate['cycle'] as $key => $value) {
            if (in_array($key, $provided['cycle'] ?? [], true)) {
                $fields['cycle.'.$key] = $value;
            }
        }
        foreach ($candidate['funding'] as $key => $value) {
            if (in_array($key, $provided['funding'] ?? [], true)) {
                $fields['funding.'.$key] = $value;
            }
        }
        if (($provided['eligibility_rules'] ?? false) && $candidate['eligibility_rules'] !== null) {
            $fields['eligibility_rules'] = $candidate['eligibility_rules'];
        }

        return $fields;
    }

    private function snapshotValue(array $snapshot, string $path): mixed
    {
        $value = match ($path) {
            'subjects' => collect($snapshot['subjects'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'eligibility_rules' => collect($snapshot['eligibility_rules'] ?? [])->map(fn ($rule) => collect($rule)->only(['rule_type', 'operator', 'normalized_value', 'unit', 'display_text', 'degree_id', 'subject_id', 'country_id', 'metadata'])->all())->values()->all(),
            default => collect(explode('.', $path))->reduce(fn ($value, $key) => is_array($value) ? ($value[$key] ?? null) : null, $snapshot),
        };
        if (in_array($path, ['cycle.opening_date', 'cycle.deadline'], true) && is_string($value)) {
            return substr($value, 0, 10);
        }

        return $value;
    }

    private function extractedValue(array $extracted, string $path): mixed
    {
        $extracted = $extracted['candidate'] ?? $extracted;
        $parts = explode('.', $path);
        if (in_array($parts[0], ['scholarship', 'cycle', 'funding'], true)) {
            return $extracted[$parts[0]][$parts[1] ?? ''] ?? null;
        }

        return $extracted[$parts[0]] ?? null;
    }

    private function materiality(string $path): string
    {
        return in_array($path, ['scholarship.university_id', 'cycle.cycle_key'], true) ? 'identity' : 'material';
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        return json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            === json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function normalizeTitle(string $title): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($title)) ?? '');
    }

    private function urlKey(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return mb_strtolower($url);
        }
        $scheme = strtolower($parts['scheme']);
        $authority = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $authority .= ':'.$port;
        }

        return $scheme.'://'.$authority.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function display(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function matchesBaseline(array $incoming, ScholarshipVersion $baseline): bool
    {
        foreach ($this->candidateFields($incoming, false) as $path => $value) {
            if (! $this->equivalent($this->snapshotValue($baseline->snapshot, $path), $value)) {
                return false;
            }
        }

        return true;
    }

    private function evidenceLocator(ProcessingRun $run, string $path): array
    {
        $phase6 = $run->extracted_payload['phase6'] ?? null;
        if (is_array($phase6)) {
            $fieldPaths = match ($path) {
                'cycle.deadline' => ['scholarship.deadline'],
                'cycle.opening_date' => ['scholarship.opening_date'],
                'cycle.application_url' => ['scholarship.application_url'],
                'subjects' => ['scholarship.subject', 'eligibility.subject'],
                'funding.tuition_amount', 'funding.tuition_currency', 'funding.tuition_period' => ['funding.tuition'],
                'funding.stipend_amount', 'funding.stipend_currency', 'funding.stipend_period' => ['funding.stipend'],
                'eligibility_rules' => array_values(array_filter(['eligibility.gpa', 'eligibility.ielts', 'eligibility.toefl', 'eligibility.pte', 'eligibility.duolingo', 'eligibility.gre', 'eligibility.gmat', 'eligibility.nationality', 'eligibility.degree', 'eligibility.subject'], fn (string $fieldPath): bool => collect($phase6['fields'] ?? [])->contains(fn (array $field): bool => ($field['path'] ?? null) === $fieldPath && ($field['status'] ?? null) !== 'missing'))),
                default => [$path],
            };
            $fields = collect($phase6['fields'] ?? [])->filter(fn (array $field): bool => in_array($field['path'] ?? null, $fieldPaths, true) && ($field['status'] ?? null) !== 'missing')->values()->all();
            if ($fields !== []) return ['phase6_fields' => $fields, 'observation_id' => $run->observation_id, 'processing_run_id' => $run->id, 'source_url' => $run->observation?->observed_url];
        }

        return ['json_pointer' => '/'.str_replace('.', '/', $path)];
    }
}
