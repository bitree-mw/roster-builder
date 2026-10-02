<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrewActivityRequest;
use App\Models\CrewActivity;
use App\Models\CrewMember;
use App\Services\CrewActivityService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/crew-activities — day planning (leave, day off, SIM, standby) for crew members. Staff only.
 */
class CrewActivityController extends Controller
{
    public function __construct(private CrewActivityService $service) {}

    /**
     * POST /crew-activities {crew_member_id, type, date_from, date_to, starts_local?, ends_local?, note?}
     * — one item per date; 422 when any date is already planned.
     */
    public function store(CrewActivityRequest $request): JsonResponse
    {
        $created = $this->service->create($request->validated(), $request->user());
        $crew = CrewMember::query()->findOrFail($request->validated('crew_member_id'));
        $label = CrewActivityService::LABELS[$request->validated('type')].($created->count() > 1 ? ' ('.$created->count().' days)' : ' on '.$created->first()->date->format('d M'));

        return ApiResponse::created(JsonResource::make($created->map(fn (CrewActivity $activity): array => $this->activity($activity))->all()), 'activity.created', ['label' => $label, 'crew' => $crew->name]);
    }

    /**
     * DELETE /crew-activities/{id}
     */
    public function destroy(Request $request, CrewActivity $crewActivity): JsonResponse
    {
        Gate::authorize('manage-operations');
        $crew = $crewActivity->crewMember()->firstOrFail();
        $this->service->delete($crewActivity, $request->user());

        return ApiResponse::success('activity.deleted', ['label' => CrewActivityService::LABELS[$crewActivity->type].' on '.$crewActivity->date->format('d M'), 'crew' => $crew->name]);
    }

    /**
     * Activity fields for the roster window.
     *
     * @return array<string, mixed>
     */
    private function activity(CrewActivity $activity): array
    {
        return ['id' => $activity->id, 'crew_member_id' => $activity->crew_member_id, 'date' => $activity->date->format('Y-m-d'), 'type' => $activity->type,
            'starts_at' => $activity->starts_at?->toIso8601String(), 'ends_at' => $activity->ends_at?->toIso8601String(), 'note' => $activity->note, 'generated' => false];
    }
}
