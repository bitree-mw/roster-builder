<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordUpdateRequest;
use App\Http\Resources\CrewMemberResource;
use App\Http\Resources\UserResource;
use App\Services\AccountService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Endpoints about the signed-in user.
 */
class ProfileController extends Controller
{
    /**
     * GET /api/v1/me — safe account fields (never the password or tokens).
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::resource(new UserResource($request->user()));
    }

    /**
     * GET /api/v1/my-profile — the caller's own crew profile; 404 when the account has no linked crew member.
     */
    public function crew(Request $request): JsonResponse
    {
        Gate::authorize('read-rosters');
        abort_unless($request->user()->crew_member_id, 404);

        return ApiResponse::resource(new CrewMemberResource($request->user()->crewMember()->with('ratings', 'documents')->firstOrFail()));
    }

    /**
     * PUT /api/v1/me/password — change your own password; other sessions and API tokens are signed out.
     */
    public function password(PasswordUpdateRequest $request, AccountService $accounts): JsonResponse
    {
        $accounts->changeOwnPassword($request->user(), $request->validated('current_password'), $request->validated('password'), $request->hasSession() ? $request->session()->getId() : null);

        return ApiResponse::success('account.password_changed');
    }
}
