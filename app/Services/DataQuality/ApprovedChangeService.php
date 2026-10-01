<?php

namespace App\Services\DataQuality;

use App\Models\FieldProvenance;
use App\Models\ProcessingRun;
use App\Models\ProposedChange;
use App\Models\RawObservation;
use App\Models\ReviewTask;
use App\Models\Scholarship;
use App\Models\ScholarshipCycle;
use App\Models\ScholarshipSource;
use App\Models\ScholarshipVersion;
use App\Models\User;
use App\Services\Scholarships\CreateScholarshipVersionService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApprovedChangeService
{
    private const OFFICIAL_SOURCE_TYPES = ['university_page', 'graduate_school', 'faculty_page', 'official_pdf', 'government', 'official_agency', 'other_official'];

    public function __construct(private readonly CreateScholarshipVersionService $versions) {}

    public function apply(ReviewTask $task, User $actor): ?ScholarshipVersion
    {
        $proposals = $task->proposals()->orderBy('proposed_changes.id')->get();
        $source = $task->processingRun?->observation?->source;
        $needsSourceAttachment = $source !== null && $source->scholarship_id === null && $task->target_scholarship_id !== null;
        if ($proposals->isEmpty() && ! $needsSourceAttachment) {
            return null;
        }
        $scholarship = $task->target_scholarship_id ? Scholarship::query()->lockForUpdate()->findOrFail($task->target_scholarship_id) : null;

        if ($scholarship === null) {
            $candidate = $task->processingRun?->normalized_payload ?? [];
            $universityId = $this->proposalValue($proposals, 'scholarship.university_id') ?? data_get($candidate, 'scholarship.university_id');
            $title = $this->proposalValue($proposals, 'scholarship.title') ?? data_get($candidate, 'scholarship.title');
            if (! $universityId || ! is_string($title) || $title === '') {
                throw ValidationException::withMessages(['review' => 'A new scholarship requires a validated university and title.']);
            }
            $scholarship = Scholarship::query()->create([
                'university_id' => $universityId,
                'title' => $title,
                'slug' => $this->uniqueSlug($title),
                'description' => $this->proposalValue($proposals, 'scholarship.description'),
                'official_url' => $this->proposalValue($proposals, 'scholarship.official_url'),
                'lifecycle_status' => 'active',
            ]);
        }

        $versionSource = $source;
        $sourceAttached = false;
        if ($source !== null) {
            $source = ScholarshipSource::query()->lockForUpdate()->findOrFail($source->id);
            if ($source->status !== 'active' || ! in_array($source->source_type, self::OFFICIAL_SOURCE_TYPES, true)) {
                throw ValidationException::withMessages(['source_id' => 'Approved changes require an active official evidence source.']);
            }
            if ($source->scholarship_id !== null && (int) $source->scholarship_id !== (int) $scholarship->id) {
                throw ValidationException::withMessages(['source_id' => 'The observation source is already associated with another scholarship.']);
            }
            if ($source->university_id !== null && (int) $source->university_id !== (int) $scholarship->university_id) {
                throw ValidationException::withMessages(['source_id' => 'The observation source belongs to a different university.']);
            }
            $existingSource = $scholarship->sources()->where('source_url_hash', $source->source_url_hash)->first();
            if ($existingSource !== null) {
                $versionSource = $existingSource;
            }
            if ($source->scholarship_id === null) {
                if ($existingSource === null) {
                    $source->forceFill(['scholarship_id' => $scholarship->id])->save();
                    $sourceAttached = true;
                }
            }
        }

        if ($proposals->isEmpty() && ! $sourceAttached) {
            return null;
        }

        $cycle = $task->target_cycle_id
            ? ScholarshipCycle::query()->lockForUpdate()->findOrFail($task->target_cycle_id)
            : null;

        if ($cycle === null) {
            $cycleKey = $this->proposalValue($proposals, 'cycle.cycle_key') ?? data_get($task->processingRun?->normalized_payload, 'cycle.cycle_key');
            if (! is_string($cycleKey) || $cycleKey === '') {
                throw ValidationException::withMessages(['review' => 'A new cycle requires a cycle key.']);
            }
            if ($scholarship->cycles()->where('cycle_key', $cycleKey)->exists()) {
                throw ValidationException::withMessages(['cycle.cycle_key' => 'That cycle already exists for this scholarship; the proposal must be redetected against its latest version.']);
            }
            $cycle = $scholarship->cycles()->create([
                'cycle_key' => $cycleKey,
                'label' => $this->proposalValue($proposals, 'cycle.label') ?? $cycleKey,
                'opening_date' => $this->proposalValue($proposals, 'cycle.opening_date'),
                'deadline' => $this->proposalValue($proposals, 'cycle.deadline'),
                'application_url' => $this->proposalValue($proposals, 'cycle.application_url'),
                'status' => 'draft',
            ]);
            $cycle->funding()->create(['classification' => 'unknown']);
        }

        $latest = $cycle->versions()->orderByDesc('version_number')->first();
        if ($latest !== null && $task->target_cycle_id !== null) {
            $baselineId = $proposals->first()?->baseline_version_id;
            if ((int) $latest->id !== (int) $baselineId) {
                throw ValidationException::withMessages(['baseline' => 'The proposal baseline changed and must be marked stale before approval.']);
            }
        }

        $scholarshipChanges = [];
        $cycleChanges = [];
        $fundingChanges = [];
        $eligibilityReplacement = null;
        $subjects = null;
        foreach ($proposals as $proposal) {
            $path = $proposal->field_path;
            if (str_starts_with($path, 'scholarship.')) {
                $key = substr($path, strlen('scholarship.'));
                if (in_array($key, ['title', 'description', 'official_url', 'university_id'], true)) {
                    $scholarshipChanges[$key] = $proposal->proposed_value;
                }
            } elseif ($path === 'subjects') {
                $subjects = $proposal->proposed_value;
            } elseif (str_starts_with($path, 'cycle.')) {
                $key = substr($path, strlen('cycle.'));
                if (in_array($key, ['cycle_key', 'label', 'opening_date', 'deadline', 'application_url'], true)) {
                    $cycleChanges[$key] = $proposal->proposed_value;
                }
            } elseif (str_starts_with($path, 'funding.')) {
                $fundingChanges[substr($path, strlen('funding.'))] = $proposal->proposed_value;
            } elseif ($path === 'eligibility_rules') {
                $eligibilityReplacement = $proposal->proposed_value;
            }
        }

        if ($scholarshipChanges !== []) {
            $scholarship->fill($scholarshipChanges)->save();
        }
        if ($subjects !== null) {
            $scholarship->subjects()->sync($subjects);
        }
        if ($cycleChanges !== []) {
            if (isset($cycleChanges['cycle_key']) && $cycle->scholarship->cycles()->where('cycle_key', $cycleChanges['cycle_key'])->where('id', '<>', $cycle->id)->exists()) {
                throw ValidationException::withMessages(['cycle.cycle_key' => 'The approved cycle key conflicts with an existing cycle.']);
            }
            $cycle->fill($cycleChanges)->save();
        }
        if ($fundingChanges !== []) {
            $allowed = ['classification', 'notes'];
            foreach (['tuition', 'stipend', 'accommodation', 'health_insurance', 'travel', 'visa', 'research_grant', 'application_fee', 'other_benefits'] as $benefit) {
                array_push($allowed, $benefit.'_amount', $benefit.'_currency', $benefit.'_period');
            }
            $fundingChanges = array_intersect_key($fundingChanges, array_flip($allowed));
            if (isset($fundingChanges['classification']) && $fundingChanges['classification'] === null) {
                $fundingChanges['classification'] = 'unknown';
            }
            $cycle->funding()->updateOrCreate(['cycle_id' => $cycle->id], ['classification' => 'unknown', ...$fundingChanges]);
        } elseif (! $cycle->funding()->exists()) {
            $cycle->funding()->create(['classification' => 'unknown']);
        }
        if (is_array($eligibilityReplacement)) {
            $cycle->eligibilityRules()->delete();
            foreach ($eligibilityReplacement as $rule) {
                $rule['operator'] = ['eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$rule['operator']] ?? $rule['operator'];
                $cycle->eligibilityRules()->create($rule);
            }
        }

        $eligibilityState = $cycle->eligibilityRules()->exists() ? 'provided' : 'unknown';
        $version = $this->versions->create($cycle, $actor, 'data_quality_review', $versionSource?->id, ['eligibility_state' => $eligibilityState]);
        $approvalRun = $task->processingRun;
        $approvalObservation = $approvalRun?->observation;
        $this->createProvenance($version, $latest, $proposals, $source, false, $approvalObservation, $approvalRun);

        if ($scholarshipChanges !== [] || $subjects !== null || $sourceAttached) {
            $siblingProposals = $proposals->filter(fn (ProposedChange $proposal) => str_starts_with($proposal->field_path, 'scholarship.') || $proposal->field_path === 'subjects')->values();
            $cycles = ScholarshipCycle::query()->where('scholarship_id', $scholarship->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($cycles as $sibling) {
                if ((int) $sibling->id === (int) $cycle->id) {
                    continue;
                }
                $previous = $sibling->versions()->orderByDesc('version_number')->first();
                $siblingState = $sibling->eligibilityRules()->exists() ? 'provided' : 'unknown';
                $siblingVersion = $this->versions->create($sibling, $actor, 'data_quality_review', $versionSource?->id, ['eligibility_state' => $siblingState]);
                $this->createProvenance($siblingVersion, $previous, $siblingProposals, $source, $sourceAttached, $approvalObservation, $approvalRun);
            }
        }

        return $version;
    }

    private function createProvenance(ScholarshipVersion $version, ?ScholarshipVersion $previous, $proposals, ?ScholarshipSource $source, bool $sourceAttached = false, ?RawObservation $approvalObservation = null, ?ProcessingRun $approvalRun = null): void
    {
        $snapshot = $version->snapshot;
        $values = [];
        foreach (($snapshot['scholarship'] ?? []) as $key => $value) {
            $values['scholarship.'.$key] = $value;
        }
        foreach (($snapshot['cycle'] ?? []) as $key => $value) {
            $values['cycle.'.$key] = $value;
        }
        $values['subjects'] = collect($snapshot['subjects'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $values['sources'] = collect($snapshot['sources'] ?? [])->map(fn ($item) => collect($item)->only(['id', 'source_type', 'source_url', 'is_primary', 'status'])->all())->values()->all();
        foreach (($snapshot['funding'] ?? []) as $key => $value) {
            if (! in_array($key, ['id', 'cycle_id', 'created_at', 'updated_at', 'verification_status'], true)) {
                $values['funding.'.$key] = $value;
            }
        }
        $values['eligibility_rules'] = collect($snapshot['eligibility_rules'] ?? [])->map(fn ($rule) => collect($rule)->only(['rule_type', 'operator', 'normalized_value', 'unit', 'display_text', 'degree_id', 'subject_id', 'country_id', 'metadata'])->all())->values()->all();

        $priorByField = $previous?->fieldProvenance()->orderByDesc('id')->get()->groupBy('field_path') ?? collect();
        $approvedByField = $proposals->keyBy('field_path');
        foreach ($values as $fieldPath => $value) {
            /** @var ProposedChange|null $proposal */
            $proposal = $approvedByField->get($fieldPath);
            if ($proposal !== null) {
                $observation = $proposal->observation;
                $this->storeProvenance($version, $fieldPath, $source, $observation, $proposal->processingRun, 'approved', ['json_pointer' => '/'.str_replace('.', '/', $fieldPath)]);

                continue;
            }

            if ($fieldPath === 'sources' && $sourceAttached && $source !== null) {
                $this->storeProvenance($version, $fieldPath, $source, $approvalObservation, $approvalRun, 'approved', ['source_id' => $source->id]);

                continue;
            }

            $priorRows = $priorByField->get($fieldPath, collect());
            if ($priorRows->isNotEmpty()) {
                foreach ($priorRows as $prior) {
                    $this->storeProvenance($version, $fieldPath, $prior->source, $prior->observation, $prior->processingRun, 'carried_forward', $prior->evidence_locator, $prior->source_url_snapshot, $prior->observed_at, $prior->extraction_method, $prior->parser_version);
                }

                continue;
            }
            $this->storeProvenance($version, $fieldPath, null, null, null, 'manual_recorded', null);
        }
    }

    private function storeProvenance(
        ScholarshipVersion $version,
        string $fieldPath,
        ?ScholarshipSource $source,
        $observation,
        $processingRun,
        string $reviewState,
        ?array $locator,
        ?string $sourceUrlSnapshot = null,
        $observedAt = null,
        ?string $method = null,
        ?string $parserVersion = null,
    ): void {
        $sourceUrlSnapshot ??= $source?->source_url;
        $observedAt ??= $observation?->observed_at;
        $method ??= $processingRun?->parser_name ?? 'manual';
        $parserVersion ??= $processingRun?->parser_version;
        $key = hash('sha256', implode('|', [
            $reviewState,
            (string) ($source?->id ?? ''),
            (string) ($observation?->id ?? ''),
            (string) ($processingRun?->id ?? ''),
            json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        ]));

        FieldProvenance::query()->firstOrCreate([
            'version_id' => $version->id,
            'field_path' => $fieldPath,
            'provenance_key' => $key,
        ], [
            'scholarship_id' => $version->scholarship_id,
            'cycle_id' => $version->cycle_id,
            'source_id' => $source?->id,
            'source_url_snapshot' => $sourceUrlSnapshot,
            'raw_observation_id' => $observation?->id,
            'processing_run_id' => $processingRun?->id,
            'observed_at' => $observedAt,
            'extraction_method' => $method,
            'parser_version' => $parserVersion,
            'review_state' => $reviewState,
            'provenance_key' => $key,
            'evidence_locator' => $locator,
        ]);
    }

    private function proposalValue($proposals, string $path): mixed
    {
        return $proposals->firstWhere('field_path', $path)?->proposed_value;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'scholarship';
        $slug = $base;
        $suffix = 2;
        while (Scholarship::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
