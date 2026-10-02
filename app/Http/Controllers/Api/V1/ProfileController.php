<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CrewMemberResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProfileController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function crew(Request $request): CrewMemberResource
    {
        Gate::authorize('read-rosters');
        abort_unless($request->user()->crew_member_id, 404);

        return new CrewMemberResource($request->user()->crewMember()->with('ratings', 'documents')->firstOrFail());
    }
}
