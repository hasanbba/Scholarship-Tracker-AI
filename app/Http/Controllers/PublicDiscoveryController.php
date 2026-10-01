<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\V1\Discovery\ScholarshipSearchRequest;
use App\Http\Resources\Api\V1\PublicScholarshipResource;
use App\Models\Country;
use App\Models\Degree;
use App\Models\ScholarshipCycle;
use App\Models\Subject;
use App\Models\University;
use App\Services\Discovery\PublicScholarshipQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicDiscoveryController extends Controller
{
    public function home(PublicScholarshipQuery $discovery): View
    {
        $featured = $discovery->query()->limit(6)->get()
            ->map(fn (ScholarshipCycle $cycle): array => (new PublicScholarshipResource($cycle))->resolve(request()));

        return view('discovery.home', [
            'featured' => $featured,
            'title' => 'Find verified scholarship opportunities',
            'description' => 'Explore scholarship cycles backed by current verification and official sources.',
        ]);
    }

    public function index(ScholarshipSearchRequest $request, PublicScholarshipQuery $discovery): View
    {
        $filters = $request->validated();
        $results = $discovery->paginate($filters, (int) ($filters['per_page'] ?? 12));
        $results->setCollection($results->getCollection()->map(fn (ScholarshipCycle $cycle): array => (new PublicScholarshipResource($cycle))->resolve($request)));

        return view('discovery.index', [
            'results' => $results,
            'filters' => $filters,
            'title' => 'Scholarships',
            'description' => 'Search currently published scholarship opportunities by keyword, location, subject, degree, funding, and deadline.',
            'robots' => $this->isFiltered($filters) ? 'noindex,follow' : 'index,follow',
            'canonical' => $request->url().($request->query('page') ? '?page='.$results->currentPage() : ''),
        ]);
    }

    public function show(Request $request, string $slug, PublicScholarshipQuery $discovery): View
    {
        abort_if(array_diff(array_keys($request->query()), ['cycle']) !== [], 422);
        $validated = validator($request->query(), ['cycle' => ['nullable', 'string', 'max:80']])->validate();
        $query = $discovery->query(['sort' => 'deadline_asc'])
            ->whereHas('publishedVersion', fn ($version) => $version->whereJsonContains('snapshot->scholarship', ['slug' => $slug]));

        if (! empty($validated['cycle'])) {
            $query->whereHas('publishedVersion', fn ($version) => $version->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.cycle.cycle_key')) = ?", [$validated['cycle']]));
        }

        $cycle = $query->firstOrFail();
        $this->attachOfficialSources($cycle, $discovery);
        $related = $discovery->related($cycle);

        $snapshot = $cycle->publishedVersion->snapshot;

        return view('discovery.show', [
            'cycle' => $cycle,
            'scholarship' => (new PublicScholarshipResource($cycle))->resolve($request),
            'relatedItems' => $related->map(fn (ScholarshipCycle $relatedCycle): array => (new PublicScholarshipResource($relatedCycle))->resolve($request)),
            'related' => $related,
            'title' => $snapshot['scholarship']['title'] ?? 'Scholarship opportunity',
            'description' => $this->description($snapshot['scholarship']['description'] ?? '', 'A verified scholarship opportunity with official application information.'),
            'canonical' => route('public.scholarships.show', ['slug' => $snapshot['scholarship']['slug'] ?? $slug, ...(isset($validated['cycle']) ? ['cycle' => $validated['cycle']] : [])]),
        ]);
    }

    public function university(University $university, PublicScholarshipQuery $discovery): View
    {
        abort_unless($university->status === 'active', 404);
        $university->load('country.region');

        return $this->catalogPage($university, 'university', $discovery, ['university' => $university->slug], $university->description);
    }

    public function country(Country $country, PublicScholarshipQuery $discovery): View
    {
        abort_unless($country->status === 'active', 404);
        $country->load('region');

        return $this->catalogPage($country, 'country', $discovery, ['country' => $country->slug]);
    }

    public function subject(Subject $subject, PublicScholarshipQuery $discovery): View
    {
        abort_unless($subject->status === 'active', 404);

        return $this->catalogPage($subject, 'subject', $discovery, ['subject' => $subject->slug]);
    }

    public function degree(Degree $degree, PublicScholarshipQuery $discovery): View
    {
        abort_unless($degree->status === 'active', 404);

        return $this->catalogPage($degree, 'degree', $discovery, ['degree' => $degree->slug]);
    }

    private function catalogPage($entity, string $type, PublicScholarshipQuery $discovery, array $filters, ?string $description = null): View
    {
        $results = $discovery->paginate($filters, 12);
        $results->setCollection($results->getCollection()->map(fn (ScholarshipCycle $cycle): array => (new PublicScholarshipResource($cycle))->resolve(request())));
        $name = $entity->name;

        return view('discovery.catalog', [
            'entity' => $entity,
            'type' => $type,
            'results' => $results,
            'title' => $name.' Scholarships',
            'description' => $this->description($description ?? '', 'Discover verified scholarship opportunities connected to '.$name.'.'),
            'canonical' => url()->current(),
        ]);
    }

    private function attachOfficialSources(ScholarshipCycle $cycle, PublicScholarshipQuery $discovery): void
    {
        $cycle->setAttribute('public_official_sources', $discovery->activeOfficialSources($cycle)->map(fn ($source): array => $source->only(['source_type', 'source_name', 'source_url']))->all());
    }

    private function isFiltered(array $filters): bool
    {
        return collect($filters)->except(['page', 'per_page', 'sort'])->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
    }

    private function description(string $value, string $fallback): string
    {
        $description = trim(strip_tags($value));

        return mb_substr($description === '' ? $fallback : $description, 0, 300);
    }
}
