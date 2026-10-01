<?php

namespace App\Services\Discovery;

use App\Models\ScholarshipCycle;
use App\Models\ScholarshipSource;
use App\Models\ScholarshipVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PublicScholarshipQuery
{
    private const OFFICIAL_SOURCE_TYPES = [
        'university_page', 'graduate_school', 'faculty_page', 'official_pdf', 'government', 'official_agency', 'other_official',
    ];

    public function query(array $filters = []): Builder
    {
        $query = ScholarshipCycle::query()
            ->whereIn('scholarship_cycles.status', ['open', 'closed'])
            ->whereHas('scholarship', fn (Builder $scholarship) => $scholarship->where('lifecycle_status', '!=', 'archived'))
            ->whereHas('publishedVersion', fn (Builder $version) => $this->constrainPublicVersion($version, $filters))
            ->with(['publishedVersion.verificationRecords' => fn ($records) => $records->limit(1)]);

        return $this->sort($query, $filters['sort'] ?? 'newest');
    }

    public function paginate(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        return $this->query($filters)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function activeOfficialSources(ScholarshipCycle $cycle): Collection
    {
        $version = $cycle->publishedVersion;
        $snapshotIds = collect($version?->snapshot['sources'] ?? [])
            ->filter(fn (array $source): bool => ($source['status'] ?? null) === 'active'
                && in_array($source['source_type'] ?? null, self::OFFICIAL_SOURCE_TYPES, true))
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id);

        if ($snapshotIds->isEmpty()) {
            return collect();
        }

        return ScholarshipSource::query()
            ->where('scholarship_id', $version->scholarship_id)
            ->where('status', 'active')
            ->whereIn('source_type', self::OFFICIAL_SOURCE_TYPES)
            ->whereIn('id', $snapshotIds)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['id', 'source_type', 'source_name', 'source_url']);
    }

    public function related(ScholarshipCycle $cycle, int $limit = 4): Collection
    {
        $snapshot = $cycle->publishedVersion->snapshot;
        $query = $this->query()
            ->where('scholarship_cycles.scholarship_id', '!=', $cycle->scholarship_id)
            ->whereHas('publishedVersion', function (Builder $version) use ($snapshot): void {
                $subjects = collect($snapshot['subjects'] ?? [])->pluck('slug')->filter()->values();

                if ($subjects->isNotEmpty()) {
                    $version->where(function (Builder $subjectQuery) use ($subjects): void {
                        foreach ($subjects as $slug) {
                            $subjectQuery->orWhereJsonContains('snapshot->subjects', ['slug' => $slug]);
                        }
                    });
                } elseif (isset($snapshot['university']['country']['slug'])) {
                    $version->whereJsonContains('snapshot->university->country', ['slug' => $snapshot['university']['country']['slug']]);
                } else {
                    $version->whereRaw('1 = 0');
                }
            });

        return $query->limit($limit)->get();
    }

    private function constrainPublicVersion(Builder $version, array $filters): void
    {
        $version->where(function (Builder $deadline): void {
            $deadline->whereRaw("JSON_EXTRACT(snapshot, '$.cycle.deadline') IS NULL")
                ->orWhereRaw("JSON_TYPE(JSON_EXTRACT(snapshot, '$.cycle.deadline')) = 'NULL'")
                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.cycle.deadline')) >= ?", [now(config('app.timezone'))->toDateString()]);
        })->whereHas('verificationRecords', function (Builder $decision): void {
            $decision->where('status', 'verified')
                ->whereRaw('verification_records.id = (select latest.id from verification_records as latest where latest.version_id = verification_records.version_id order by latest.decided_at desc, latest.id desc limit 1)');
        })->whereExists(function ($source): void {
            $source->selectRaw('1')
                ->from('scholarship_sources as public_sources')
                ->whereColumn('public_sources.scholarship_id', 'scholarship_versions.scholarship_id')
                ->where('public_sources.status', 'active')
                ->whereIn('public_sources.source_type', self::OFFICIAL_SOURCE_TYPES)
                ->whereRaw("JSON_CONTAINS(JSON_EXTRACT(scholarship_versions.snapshot, '$.sources'), JSON_OBJECT('id', public_sources.id, 'source_type', public_sources.source_type, 'status', 'active'))");
        });

        $this->applySnapshotFilters($version, $filters);
    }

    private function applySnapshotFilters(Builder $version, array $filters): void
    {
        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%'.addcslashes($keyword, '\\%_').'%';
            $version->where(function (Builder $search) use ($like): void {
                $search->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.scholarship.title')) LIKE ?", [$like])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.scholarship.description')) LIKE ?", [$like])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.university.name')) LIKE ?", [$like]);
            });
        }

        foreach (['country' => 'university->country', 'region' => 'university->region', 'university' => 'university'] as $key => $path) {
            if (! empty($filters[$key])) {
                $version->whereJsonContains('snapshot->'.$path, ['slug' => $filters[$key]]);
            }
        }

        if (! empty($filters['subject'])) {
            $version->where(function (Builder $subject) use ($filters): void {
                $subject->whereJsonContains('snapshot->subjects', ['slug' => $filters['subject']])
                    ->orWhereJsonContains('snapshot->eligibility_rules', ['subject' => ['slug' => $filters['subject']]]);
            });
        }

        if (! empty($filters['degree'])) {
            $version->whereJsonContains('snapshot->eligibility_rules', ['degree' => ['slug' => $filters['degree']]]);
        }

        if (! empty($filters['funding_classification'])) {
            $version->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.funding.classification')) = ?", [$filters['funding_classification']]);
        }

        if (! empty($filters['cycle'])) {
            $version->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.cycle.cycle_key')) = ?", [$filters['cycle']]);
        }

        if (! empty($filters['deadline_from'])) {
            $version->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.cycle.deadline')) >= ?", [$filters['deadline_from']]);
        }

        if (! empty($filters['deadline_to'])) {
            $version->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.cycle.deadline')) <= ?", [$filters['deadline_to']]);
        }
    }

    private function sort(Builder $query, string $sort): Builder
    {
        $deadline = "(select JSON_UNQUOTE(JSON_EXTRACT(scholarship_versions.snapshot, '$.cycle.deadline')) from scholarship_versions where scholarship_versions.id = scholarship_cycles.published_version_id)";
        $deadlineMissing = "($deadline is null or $deadline = 'null' or $deadline = '')";
        $title = "(select lower(JSON_UNQUOTE(JSON_EXTRACT(scholarship_versions.snapshot, '$.scholarship.title'))) from scholarship_versions where scholarship_versions.id = scholarship_cycles.published_version_id)";
        $created = ScholarshipVersion::query()
            ->select('created_at')
            ->whereColumn('scholarship_versions.id', 'scholarship_cycles.published_version_id');

        return match ($sort) {
            'deadline_asc' => $query->orderByRaw("case when $deadlineMissing then 1 else 0 end asc")
                ->orderByRaw("$deadline asc")
                ->orderBy('scholarship_cycles.id'),
            'deadline_desc' => $query->orderByRaw("case when $deadlineMissing then 1 else 0 end asc")
                ->orderByRaw("$deadline desc")
                ->orderBy('scholarship_cycles.id'),
            'title_asc' => $query->orderByRaw("$title asc")->orderBy('scholarship_cycles.id'),
            'title_desc' => $query->orderByRaw("$title desc")->orderBy('scholarship_cycles.id'),
            default => $query->orderByDesc($created)->orderByDesc('scholarship_cycles.id'),
        };
    }
}
