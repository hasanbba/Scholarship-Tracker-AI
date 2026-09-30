<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminFoundationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('admin.access');

        return response()->json(ApiResponse::success('Administrative foundation access confirmed.', ['authorized' => true]));
    }
}
