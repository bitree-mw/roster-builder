<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RosterPeriodRequest;
use App\Http\Requests\RosterQueryRequest;
use App\Http\Resources\RosterPeriodResource;
use App\Models\RosterPeriod;
use App\Services\RosterPeriodService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/roster-periods — monthly roster periods. Crew see only published periods and only their own seats.
 */
class RosterPeriodController extends Controller
{
    /**
     * GET /roster-periods?month=YYYY-MM. For crew accounts the trips and assignments are filtered to the
     * caller's own crew member, so peer records and unpublished data never leave the server.
     */
    public function index(RosterQueryRequest $request): JsonResponse
    {
        $user = $request->user();
        $query = RosterPeriod::query()->when($request->validated('month'), fn ($query, $month) => $query->where('month', $month.'-01'))
            ->when(! $user->isStaff(), fn ($query) => $query->where('status', 'published'))
            ->with(['trips' => function ($query) use ($user): void {
                if (! $user->isStaff()) {
                    $query->whereHas('assignments', fn ($query) => $query->where('crew_member_id', $user->crew_member_id));
                }
                $query->orderBy('start_date')->orderBy('id')->with(['assignments' => function ($query) use ($user): void {
                    if (! $user->isStaff()) {
                        $query->where('crew_member_id', $user->crew_member_id);
                    }
                    $query->orderBy('rank')->orderBy('seat_number');
                }]);
            }])->orderByDesc('month')->paginate(12);

        return ApiResponse::resource(RosterPeriodResource::collection($query));
    }

    /**
     * POST /roster-periods — 201 for a new draft, 200 when the month already exists (idempotent).
     */
    public function store(RosterPeriodRequest $request, RosterPeriodService $service): JsonResponse
    {
        $period = $service->create($request->validated('month'), $request->user());
        $label = ['label' => $period->month->format('F Y')];

        return $period->wasRecentlyCreated
            ? ApiResponse::created(new RosterPeriodResource($period), 'roster_period.created', $label)
            : ApiResponse::resource(new RosterPeriodResource($period), 'roster_period.exists', $label);
    }
}
