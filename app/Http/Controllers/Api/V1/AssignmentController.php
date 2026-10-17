<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignmentRequest;
use App\Http\Requests\AssignmentSplitRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Exclusion;
use App\Services\AssignmentService;
use App\Services\RosterLegalityService;
use App\Support\Api\ApiResponse;
use App\Support\Roster\Section;
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
     * PUT /assignments/{id} {crew_member_id|null, override_reason?, target_assignment_id?} — assign, clear,
     * or atomically transfer/exchange holders when a destination or exchange seat is supplied.
     * 422 lists the broken rules when no override reason is given.
     */
    public function update(AssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        $seat = match (true) {
            $request->has('exchange_assignment_id') => $this->service->exchange($assignment, (int) $request->validated('exchange_assignment_id'), $request->validated('crew_member_id'), (int) $request->validated('expected_crew_member_id'), $request->user()),
            $request->has('target_assignment_id') => $this->service->moveDate($assignment, (int) $request->validated('target_assignment_id'), $request->validated('crew_member_id'), $request->validated('override_reason'), $request->user()),
            default => $this->service->assign($assignment, $request->validated('crew_member_id'), $request->validated('override_reason'), $request->user()),
        };
        $label = $this->label($seat);
        $key = match ($seat->decision_log['action'] ?? null) {
            'cleared' => 'assignment.cleared',
            'overridden' => 'assignment.overridden',
            'exchanged' => 'assignment.exchanged',
            default => 'assignment.assigned',
        };

        return ApiResponse::resource(new AssignmentResource($seat), $key, ['label' => $label, 'crew' => $seat->crewMember?->name]);
    }

    /**
     * POST /assignments/{id}/split {after_leg} — captain seats only: the captain changes after that leg. The
     * seat keeps its holder up to the stop; a new open captain seat covers the rest of the trip.
     */
    public function split(AssignmentSplitRequest $request, Assignment $assignment): JsonResponse
    {
        [$seat, $section] = $this->service->split($assignment, (int) $request->validated('after_leg'), $request->user());
        $snapshot = $seat->trip->schedule_snapshot;

        return ApiResponse::resource(new AssignmentResource($seat), 'assignment.split', ['label' => $this->label($seat, false),
            'stop' => Section::ends($snapshot, $seat->from_leg, $seat->to_leg)[1], 'first' => Section::route($snapshot, $seat->from_leg, $seat->to_leg), 'second' => Section::route($snapshot, $section->from_leg, $section->to_leg)]);
    }

    /**
     * POST /assignments/{id}/join — join a captain section with the next one; the joined seat is open.
     */
    public function join(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('manage-operations');
        ['seat' => $seat, 'released' => $released] = $this->service->join($assignment, $request->user());

        return ApiResponse::resource(new AssignmentResource($seat->load('crewMember')), $released === [] ? 'assignment.joined' : 'assignment.joined_released',
            ['label' => $this->label($seat, false), 'crew' => implode(' and ', $released)]);
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
