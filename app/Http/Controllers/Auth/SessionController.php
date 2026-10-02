<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SessionController extends Controller
{
    public function store(LoginRequest $request, SessionService $service): UserResource
    {
        return new UserResource($service->login($request->validated(), $request->session()));
    }

    public function destroy(Request $request, SessionService $service): Response
    {
        $service->logout($request->session());

        return response()->noContent();
    }
}
