<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return response()->json(ApiResponse::success(
            'Account retrieved successfully.',
            UserResource::make($user->load('roles.permissions'))->resolve(),
        ));
    }
}
