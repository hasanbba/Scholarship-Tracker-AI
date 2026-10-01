<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Scholarships\RejectScholarshipVersionRequest;
use App\Http\Requests\Api\V1\Scholarships\VerifyScholarshipVersionRequest;
use App\Http\Resources\Api\V1\VerificationRecordResource;
use App\Models\ScholarshipVersion;
use App\Services\Scholarships\VerificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ScholarshipVersionWorkflowController extends Controller
{
    public function verify(VerifyScholarshipVersionRequest $request, ScholarshipVersion $version, VerificationService $service): JsonResponse
    {
        $record = $service->decide($version, $request->user(), 'verified', (int) $request->validated('source_id'), $request->validated('notes'));

        return response()->json(ApiResponse::success('Scholarship version verified successfully.', (new VerificationRecordResource($record))->resolve()), 201);
    }

    public function reject(RejectScholarshipVersionRequest $request, ScholarshipVersion $version, VerificationService $service): JsonResponse
    {
        $record = $service->decide($version, $request->user(), 'rejected', $request->validated('source_id'), $request->validated('notes'));

        return response()->json(ApiResponse::success('Scholarship version rejected successfully.', (new VerificationRecordResource($record))->resolve()), 201);
    }
}
