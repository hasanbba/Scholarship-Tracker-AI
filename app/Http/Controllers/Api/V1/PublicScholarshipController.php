<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Discovery\ScholarshipSearchRequest;
use App\Http\Resources\Api\V1\PublicScholarshipResource;
use App\Services\Discovery\PublicScholarshipQuery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicScholarshipController extends Controller
{
    public function index(ScholarshipSearchRequest $request, PublicScholarshipQuery $discovery): JsonResponse
    {
        $filters = $request->validated();
        $paginator = $discovery->paginate($filters, (int) ($filters['per_page'] ?? 12));

        return response()->json(ApiResponse::success('Scholarships retrieved successfully.', [
            'items' => PublicScholarshipResource::collection($paginator->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]));
    }

    public function show(Request $request, string $slug, PublicScholarshipQuery $discovery): JsonResponse
    {
        abort_if(array_diff(array_keys($request->query()), ['cycle']) !== [], 422);
        $validated = validator($request->query(), ['cycle' => ['nullable', 'string', 'max:80']])->validate();
        $query = $discovery->query(['sort' => 'deadline_asc'])
            ->whereHas('publishedVersion', fn ($version) => $version->whereJsonContains('snapshot->scholarship', ['slug' => $slug]));

        if (! empty($validated['cycle'])) {
            $query->whereHas('publishedVersion', fn ($version) => $version->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.cycle.cycle_key')) = ?", [$validated['cycle']]));
        }

        $cycle = $query->firstOrFail();
        $cycle->setAttribute('public_official_sources', $discovery->activeOfficialSources($cycle)->map(fn ($source): array => $source->only(['source_type', 'source_name', 'source_url']))->all());
        $related = $discovery->related($cycle)
            ->map(fn ($relatedCycle): array => (new PublicScholarshipResource($relatedCycle))->resolve($request));

        return response()->json(ApiResponse::success('Scholarship retrieved successfully.', [
            'scholarship' => (new PublicScholarshipResource($cycle))->resolve($request),
            'related' => $related,
        ]));
    }
}
