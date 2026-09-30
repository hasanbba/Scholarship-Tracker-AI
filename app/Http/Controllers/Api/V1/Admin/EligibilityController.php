<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Scholarships\EligibilityRuleRequest;
use App\Http\Resources\Api\V1\EligibilityResource;
use App\Models\ScholarshipCycle;
use App\Models\ScholarshipEligibilityRule;
use App\Services\Scholarships\CreateScholarshipVersionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EligibilityController extends Controller
{
    public function index(ScholarshipCycle $cycle): JsonResponse
    {
        $this->authorize('view', $cycle->scholarship);

        return response()->json(ApiResponse::success('Cycle eligibility retrieved successfully.', EligibilityResource::collection($cycle->eligibilityRules()->orderBy('id')->get())->resolve()));
    }

    public function store(EligibilityRuleRequest $request, ScholarshipCycle $cycle, CreateScholarshipVersionService $versions): JsonResponse
    {
        $this->authorize('manage', $cycle->scholarship);
        $rule = DB::transaction(function () use ($request, $cycle, $versions) {
            $rule = $cycle->eligibilityRules()->create($request->validated());
            $versions->create($cycle, $request->user(), 'eligibility_added');

            return $rule;
        });

        return response()->json(ApiResponse::success('Eligibility rule created successfully.', (new EligibilityResource($rule))->resolve()), 201);
    }

    public function update(EligibilityRuleRequest $request, ScholarshipEligibilityRule $rule, CreateScholarshipVersionService $versions): JsonResponse
    {
        $this->authorize('manage', $rule->cycle->scholarship);
        DB::transaction(function () use ($request, $rule, $versions) {
            $rule->fill($request->validated())->save();
            $versions->create($rule->cycle, $request->user(), 'eligibility_updated');
        });

        return response()->json(ApiResponse::success('Eligibility rule updated successfully.', (new EligibilityResource($rule->refresh()))->resolve()));
    }

    public function destroy(ScholarshipEligibilityRule $rule, CreateScholarshipVersionService $versions): JsonResponse
    {
        $this->authorize('manage', $rule->cycle->scholarship);
        DB::transaction(function () use ($rule, $versions) {
            $cycle = $rule->cycle;
            $rule->delete();
            $versions->create($cycle, request()->user(), 'eligibility_removed');
        });

        return response()->json(ApiResponse::success('Eligibility rule removed successfully.'));
    }
}
