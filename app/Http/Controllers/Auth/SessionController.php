<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\SessionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function store(LoginRequest $request, SessionService $service): JsonResponse
    {
        $user = $service->login($request->validated(), $request->session());

        return ApiResponse::resource(new UserResource($user), 'session.login', ['label' => $user->name]);
    }

    public function destroy(Request $request, SessionService $service): JsonResponse
    {
        $service->logout($request->session());

        return ApiResponse::success('session.logout');
    }
}
