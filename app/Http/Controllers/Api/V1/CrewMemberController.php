<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrewMemberRequest;
use App\Http\Requests\CrewQueryRequest;
use App\Http\Resources\CrewMemberResource;
use App\Models\CrewMember;
use App\Services\CrewMemberService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CrewMemberController extends Controller
{
    public function __construct(private CrewMemberService $service) {}

    public function index(CrewQueryRequest $request): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(CrewMemberResource::collection(CrewMember::with('ratings', 'documents')->when($request->validated('rank'), fn ($query, $rank) => $query->where('rank', $rank))->when($request->validated('base'), fn ($query, $base) => $query->where('base_airport', $base))->orderBy('name')->orderBy('id')->paginate(100)));
    }

    public function show(CrewMember $crewMember): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new CrewMemberResource($crewMember->load('ratings', 'documents')));
    }

    public function store(CrewMemberRequest $request): JsonResponse
    {
        $crew = $this->service->save($request->validated(), $request->user());

        return ApiResponse::created(new CrewMemberResource($crew), 'crew.created', ['label' => $crew->name]);
    }

    public function update(CrewMemberRequest $request, CrewMember $crewMember): JsonResponse
    {
        $crew = $this->service->save($request->validated(), $request->user(), $crewMember);

        return ApiResponse::resource(new CrewMemberResource($crew), 'crew.updated', ['label' => $crew->name]);
    }

    public function destroy(Request $request, CrewMember $crewMember): JsonResponse
    {
        Gate::authorize('manage-operations');
        $this->service->delete($crewMember, $request->user());

        return ApiResponse::success('crew.deleted', ['label' => $crewMember->name]);
    }
}
