<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\SessionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON sign-in/sign-out for the Blade client (web middleware: session, CSRF, throttled login).
 */
class SessionController extends Controller
{
    /**
     * POST /login — email or username plus password; the session is regenerated on success.
     */
    public function store(LoginRequest $request, SessionService $service): JsonResponse
    {
        $user = $service->login($request->validated(), $request->session());

        return ApiResponse::resource(new UserResource($user), 'session.login', ['label' => $user->name]);
    }

    /**
     * POST /logout — invalidates the session and rotates the CSRF token.
     */
    public function destroy(Request $request, SessionService $service): JsonResponse
    {
        $service->logout($request->session());

        return ApiResponse::success('session.logout');
    }
}
