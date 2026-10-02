<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RosterPeriodRequest;
use App\Http\Requests\RosterQueryRequest;
use App\Http\Resources\RosterPeriodResource;
use App\Models\RosterPeriod;
use App\Services\RosterPeriodService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RosterPeriodController extends Controller
{
    public function index(RosterQueryRequest $request): AnonymousResourceCollection
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

        return RosterPeriodResource::collection($query);
    }

    public function store(RosterPeriodRequest $request, RosterPeriodService $service): RosterPeriodResource
    {
        return new RosterPeriodResource($service->create($request->validated('month'), $request->user()));
    }
}
