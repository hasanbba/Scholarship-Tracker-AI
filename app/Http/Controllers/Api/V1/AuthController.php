<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::query()->create([
                'name' => $request->string('name')->toString(),
                'email' => mb_strtolower($request->string('email')->toString()),
                'password' => Hash::make($request->string('password')->toString()),
            ]);

            $studentRole = Role::query()->where('name', 'student')->firstOrFail();
            $user->roles()->attach($studentRole);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(
            ApiResponse::success('Registration successful.', UserResource::make($user->load('roles.permissions'))->resolve()),
            201,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $credentials['email'] = mb_strtolower($credentials['email']);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return response()->json(ApiResponse::failure('The provided credentials are invalid.'), 422);
        }

        $request->session()->regenerate();
        $user = $request->user()->load('roles.permissions');

        return response()->json(ApiResponse::success('Login successful.', UserResource::make($user)->resolve()));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(ApiResponse::success('Logout successful.'));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles.permissions');

        return response()->json(ApiResponse::success('Current user retrieved successfully.', UserResource::make($user)->resolve()));
    }
}
