<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Scholarships\PublishScholarshipCycleRequest;
use App\Http\Requests\Api\V1\Scholarships\UnpublishScholarshipCycleRequest;
use App\Http\Resources\Api\V1\PublicationEventResource;
use App\Models\ScholarshipCycle;
use App\Services\Scholarships\PublicationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ScholarshipCyclePublicationController extends Controller
{
    public function publish(PublishScholarshipCycleRequest $request, ScholarshipCycle $cycle, PublicationService $service): JsonResponse
    {
        $event = $service->publish($cycle, $cycle->versions()->findOrFail($request->validated('version_id')), $request->user(), $request->validated('reason'));

        return response()->json(ApiResponse::success('Scholarship cycle version published successfully.', [
            'event' => (new PublicationEventResource($event))->resolve(),
            'published_version_id' => $cycle->fresh()->published_version_id,
        ]), 201);
    }

    public function unpublish(UnpublishScholarshipCycleRequest $request, ScholarshipCycle $cycle, PublicationService $service): JsonResponse
    {
        $event = $service->unpublish($cycle, $request->user(), $request->validated('reason'));

        return response()->json(ApiResponse::success('Scholarship cycle unpublished successfully.', [
            'event' => (new PublicationEventResource($event))->resolve(),
            'published_version_id' => $cycle->fresh()->published_version_id,
        ]));
    }
}
