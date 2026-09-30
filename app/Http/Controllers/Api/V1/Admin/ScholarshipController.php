<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Scholarships\CycleRequest;
use App\Http\Requests\Api\V1\Scholarships\FundingRequest;
use App\Http\Requests\Api\V1\Scholarships\ScholarshipRequest;
use App\Http\Requests\Api\V1\Scholarships\SourceRequest;
use App\Http\Resources\Api\V1\CycleResource;
use App\Http\Resources\Api\V1\FundingResource;
use App\Http\Resources\Api\V1\ScholarshipResource;
use App\Http\Resources\Api\V1\SourceResource;
use App\Http\Resources\Api\V1\VersionResource;
use App\Models\Scholarship;
use App\Models\ScholarshipCycle;
use App\Services\Scholarships\CreateScholarshipCycleService;
use App\Services\Scholarships\CreateScholarshipService;
use App\Services\Scholarships\CreateScholarshipVersionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ScholarshipController extends Controller
{
    public function index(): JsonResponse
    {
        $items = Scholarship::query()->with(['university.country', 'subjects'])->latest()->paginate(20);

        return response()->json(['success' => true, 'message' => 'Scholarships retrieved successfully.', 'data' => ScholarshipResource::collection($items)->resolve(), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total()]]);
    }

    public function show(Scholarship $scholarship): JsonResponse
    {
        $this->authorize('view', $scholarship);

        return response()->json(ApiResponse::success('Scholarship retrieved successfully.', (new ScholarshipResource($scholarship->load(['university.country', 'subjects', 'cycles'])))->resolve()));
    }

    public function store(ScholarshipRequest $request, CreateScholarshipService $service): JsonResponse
    {
        $this->authorize('create', Scholarship::class);
        $scholarship = $service->create($request->validated(), $request->user());

        return response()->json(ApiResponse::success('Scholarship created successfully.', (new ScholarshipResource($scholarship))->resolve()), 201);
    }

    public function update(ScholarshipRequest $request, Scholarship $scholarship, CreateScholarshipVersionService $versions): JsonResponse
    {
        $this->authorize('update', $scholarship);
        DB::transaction(function () use ($request, $scholarship, $versions) {
            $data = $request->validated();
            $subjects = $data['subject_ids'] ?? null;
            unset($data['subject_ids']);
            $scholarship->fill($data)->save();
            if ($subjects !== null) {
                $scholarship->subjects()->sync($subjects);
            }
            foreach ($scholarship->cycles()->get() as $cycle) {
                $versions->create($cycle, $request->user(), 'scholarship_updated');
            }
        });

        return response()->json(ApiResponse::success('Scholarship updated successfully.', (new ScholarshipResource($scholarship->refresh()->load(['university.country', 'subjects', 'cycles'])))->resolve()));
    }

    public function cycles(Scholarship $scholarship): JsonResponse
    {
        $this->authorize('view', $scholarship);
        $items = $scholarship->cycles()->with(['funding', 'eligibilityRules'])->withCount('versions')->orderByDesc('cycle_key')->paginate(30);

        return response()->json(['success' => true, 'message' => 'Scholarship cycles retrieved successfully.', 'data' => CycleResource::collection($items)->resolve(), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total()]]);
    }

    public function storeCycle(CycleRequest $request, Scholarship $scholarship, CreateScholarshipCycleService $service): JsonResponse
    {
        $this->authorize('manage', $scholarship);
        $cycle = $service->create($scholarship, $request->validated(), $request->user());

        return response()->json(ApiResponse::success('Scholarship cycle created successfully.', (new CycleResource($cycle))->resolve()), 201);
    }

    public function updateCycle(CycleRequest $request, ScholarshipCycle $cycle, CreateScholarshipVersionService $versions): JsonResponse
    {
        $this->authorize('manage', $cycle->scholarship);
        DB::transaction(function () use ($cycle, $request, $versions) {
            $cycle->fill($request->validated())->save();
            $versions->create($cycle, $request->user(), 'cycle_updated');
        });

        return response()->json(ApiResponse::success('Scholarship cycle updated successfully.', (new CycleResource($cycle->refresh()->load(['funding', 'eligibilityRules'])))->resolve()));
    }

    public function showCycle(ScholarshipCycle $cycle): JsonResponse
    {
        $this->authorize('view', $cycle->scholarship);

        return response()->json(ApiResponse::success('Scholarship cycle retrieved successfully.', (new CycleResource($cycle->load(['funding', 'eligibilityRules'])->loadCount('versions')))->resolve()));
    }

    public function sources(Scholarship $scholarship): JsonResponse
    {
        $this->authorize('view', $scholarship);

        return response()->json(ApiResponse::success('Scholarship sources retrieved successfully.', SourceResource::collection($scholarship->sources()->orderByDesc('is_primary')->get())->resolve()));
    }

    public function storeSource(SourceRequest $request, Scholarship $scholarship, CreateScholarshipVersionService $versions): JsonResponse
    {
        $this->authorize('manage', $scholarship);
        $source = DB::transaction(function () use ($request, $scholarship, $versions) {
            $data = $request->validated();
            if (($data['is_primary'] ?? false) === true) {
                $scholarship->sources()->update(['is_primary' => false]);
            }
            $source = $scholarship->sources()->create($data);
            foreach ($scholarship->cycles()->get() as $cycle) {
                $versions->create($cycle, $request->user(), 'source_added', $source->id);
            }

            return $source;
        });

        return response()->json(ApiResponse::success('Scholarship source created successfully.', (new SourceResource($source))->resolve()), 201);
    }

    public function funding(ScholarshipCycle $cycle): JsonResponse
    {
        $this->authorize('view', $cycle->scholarship);
        $funding = $cycle->funding;

        return response()->json(ApiResponse::success('Cycle funding retrieved successfully.', $funding ? (new FundingResource($funding))->resolve() : null));
    }

    public function saveFunding(FundingRequest $request, ScholarshipCycle $cycle, CreateScholarshipVersionService $versions): JsonResponse
    {
        $this->authorize('manage', $cycle->scholarship);
        $funding = DB::transaction(function () use ($request, $cycle, $versions) {
            $funding = $cycle->funding()->updateOrCreate(['cycle_id' => $cycle->id], $request->validated());
            $versions->create($cycle, $request->user(), 'funding_updated');

            return $funding;
        });

        return response()->json(ApiResponse::success('Cycle funding saved successfully.', (new FundingResource($funding->refresh()))->resolve()));
    }

    public function versions(ScholarshipCycle $cycle): JsonResponse
    {
        $this->authorize('view', $cycle->scholarship);

        return response()->json(ApiResponse::success('Cycle versions retrieved successfully.', VersionResource::collection($cycle->versions()->orderByDesc('version_number')->get())->resolve()));
    }
}
