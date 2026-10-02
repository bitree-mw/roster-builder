<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Exclusion;
use App\Services\AssignmentService;
use App\Services\RosterLegalityService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/assignments — manual seat edits in the roster window (staff only).
 */
class AssignmentController extends Controller
{
    public function __construct(private AssignmentService $service) {}

    /**
     * GET /assignments/{id}/candidates — who could take the seat, legal choices first, with each person's
     * rule problems and hours used this week.
     */
    public function candidates(Assignment $assignment): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(JsonResource::make($this->service->candidates($assignment)));
    }

    /**
     * PUT /assignments/{id} {crew_member_id|null, override_reason?} — assign (locks the seat) or clear it.
     * 422 lists the broken rules when no override reason is given.
     */
    public function update(AssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        $seat = $this->service->assign($assignment, $request->validated('crew_member_id'), $request->validated('override_reason'), $request->user());
        $label = $this->label($seat);
        $key = match ($seat->decision_log['action'] ?? null) {
            'cleared' => 'assignment.cleared',
            'overridden' => 'assignment.overridden',
            default => 'assignment.assigned',
        };

        return ApiResponse::resource(new AssignmentResource($seat), $key, ['label' => $label, 'crew' => $seat->crewMember?->name]);
    }

    /**
     * POST /assignments/{id}/exclude — never assign the current holder to this trip again; the seat opens.
     */
    public function exclude(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('manage-operations');
        $crew = $assignment->crewMember()->value('name');
        $seat = $this->service->exclude($assignment, $request->user());

        return ApiResponse::resource(new AssignmentResource($seat->load('crewMember')), 'exclusion.created', ['label' => $this->label($seat, false), 'crew' => $crew]);
    }

    /**
     * POST /assignments/{id}/undo — restore the seat as it was before its last manual change.
     */
    public function undo(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('manage-operations');
        $seat = $this->service->undo($assignment, $request->user());

        return ApiResponse::resource(new AssignmentResource($seat), 'assignment.undone', ['label' => $this->label($seat)]);
    }

    /**
     * DELETE /exclusions/{id} — allow the crew member on the trip again.
     */
    public function include(Request $request, Exclusion $exclusion): JsonResponse
    {
        Gate::authorize('manage-operations');
        $exclusion->load('crewMember', 'trip');
        $this->service->removeExclusion($exclusion, $request->user());

        return ApiResponse::success('exclusion.deleted', ['label' => ($exclusion->trip->schedule_snapshot['code'] ?? 'the trip').' on '.$exclusion->trip->start_date->format('d M'), 'crew' => $exclusion->crewMember->name]);
    }

    /** "LB1 on 05 Oct (captain)" for messages; without the seat when $withSeat is false. */
    private function label(Assignment $seat, bool $withSeat = true): string
    {
        $seat->loadMissing('trip');
        $label = ($seat->trip->schedule_snapshot['code'] ?? 'Trip').' on '.$seat->trip->start_date->format('d M');

        return $withSeat ? $label.' ('.RosterLegalityService::RANKS[$seat->rank].')' : $label;
    }
}
