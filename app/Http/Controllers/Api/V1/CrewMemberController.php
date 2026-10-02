<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrewMemberRequest;
use App\Http\Requests\CrewQueryRequest;
use App\Http\Resources\CrewMemberResource;
use App\Models\CrewMember;
use App\Services\CrewMemberService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CrewMemberController extends Controller
{
    public function __construct(private CrewMemberService $service) {}

    public function index(CrewQueryRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('read-operations');

        return CrewMemberResource::collection(CrewMember::with('ratings', 'documents')->when($request->validated('rank'), fn ($query, $rank) => $query->where('rank', $rank))->when($request->validated('base'), fn ($query, $base) => $query->where('base_airport', $base))->orderBy('name')->orderBy('id')->paginate(100));
    }

    public function show(CrewMember $crewMember): CrewMemberResource
    {
        Gate::authorize('read-operations');

        return new CrewMemberResource($crewMember->load('ratings', 'documents'));
    }

    public function store(CrewMemberRequest $request): CrewMemberResource
    {
        return new CrewMemberResource($this->service->save($request->validated(), $request->user()));
    }

    public function update(CrewMemberRequest $request, CrewMember $crewMember): CrewMemberResource
    {
        return new CrewMemberResource($this->service->save($request->validated(), $request->user(), $crewMember));
    }

    public function destroy(Request $request, CrewMember $crewMember): Response
    {
        Gate::authorize('manage-operations');
        $this->service->delete($crewMember, $request->user());

        return response()->noContent();
    }
}
