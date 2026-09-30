<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Catalog\CountryRequest;
use App\Http\Requests\Api\V1\Catalog\DegreeRequest;
use App\Http\Requests\Api\V1\Catalog\RegionRequest;
use App\Http\Requests\Api\V1\Catalog\SubjectRequest;
use App\Http\Requests\Api\V1\Catalog\UniversityRequest;
use App\Http\Resources\Api\V1\CatalogResource;
use App\Models\Country;
use App\Models\Degree;
use App\Models\Region;
use App\Models\Subject;
use App\Models\University;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function regions(): JsonResponse
    {
        return $this->listing(Region::query());
    }

    public function countries(): JsonResponse
    {
        return $this->listing(Country::query()->with('region'));
    }

    public function universities(): JsonResponse
    {
        return $this->listing(University::query()->with('country'));
    }

    public function subjects(): JsonResponse
    {
        return $this->listing(Subject::query());
    }

    public function degrees(): JsonResponse
    {
        return $this->listing(Degree::query());
    }

    public function storeRegion(RegionRequest $request): JsonResponse
    {
        return $this->store($request, new Region);
    }

    public function updateRegion(RegionRequest $request, Region $region): JsonResponse
    {
        return $this->update($request, $region);
    }

    public function storeCountry(CountryRequest $request): JsonResponse
    {
        return $this->store($request, new Country);
    }

    public function updateCountry(CountryRequest $request, Country $country): JsonResponse
    {
        return $this->update($request, $country);
    }

    public function storeUniversity(UniversityRequest $request): JsonResponse
    {
        return $this->store($request, new University);
    }

    public function updateUniversity(UniversityRequest $request, University $university): JsonResponse
    {
        return $this->update($request, $university);
    }

    public function storeSubject(SubjectRequest $request): JsonResponse
    {
        return $this->store($request, new Subject);
    }

    public function updateSubject(SubjectRequest $request, Subject $subject): JsonResponse
    {
        return $this->update($request, $subject);
    }

    public function storeDegree(DegreeRequest $request): JsonResponse
    {
        return $this->store($request, new Degree);
    }

    public function updateDegree(DegreeRequest $request, Degree $degree): JsonResponse
    {
        return $this->update($request, $degree);
    }

    private function listing($query): JsonResponse
    {
        $items = $query->where('status', 'active')->orderBy('name')->paginate(50);

        return response()->json(['success' => true, 'message' => 'Catalog retrieved successfully.', 'data' => CatalogResource::collection($items)->resolve(), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total()]]);
    }

    private function store($request, Model $model): JsonResponse
    {
        $model->fill($request->validated());
        $model->save();

        return response()->json(ApiResponse::success('Catalog item created successfully.', (new CatalogResource($model->refresh()))->resolve()), 201);
    }

    private function update($request, Model $model): JsonResponse
    {
        $model->fill($request->validated());
        $model->save();

        return response()->json(ApiResponse::success('Catalog item updated successfully.', (new CatalogResource($model->refresh()))->resolve()));
    }
}
