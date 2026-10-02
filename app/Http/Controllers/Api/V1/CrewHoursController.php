<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrewHoursQueryRequest;
use App\Models\CrewMember;
use App\Services\CrewHoursService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/crew-hours and /api/v1/my-hours — accumulated block and duty hours from published rosters.
 */
class CrewHoursController extends Controller
{
    public function __construct(private CrewHoursService $hours) {}

    /**
     * GET /crew-hours?group=pilots|cabin — staff report for every active crew member of the group (plus
     * inactive crew who still have hours this year), with group totals per window.
     */
    public function index(CrewHoursQueryRequest $request): JsonResponse
    {
        $group = $request->validated('group') ?? 'pilots';
        $crews = CrewMember::query()->whereIn('rank', $group === 'cabin' ? ['CC'] : ['CPT', 'FO'])->orderBy('name')->orderBy('id')->get();
        $summaries = $this->hours->summaries($crews);
        $rows = $crews->filter(fn (CrewMember $crew): bool => $crew->active || $summaries[$crew->id]['year']['trips'] > 0)->values()
            ->map(fn (CrewMember $crew): array => ['crew' => $this->crew($crew), 'hours' => $summaries[$crew->id]])->all();
        $totals = [];
        foreach (array_keys(CrewHoursService::WINDOWS) as $window) {
            foreach (['block_minutes', 'duty_minutes', 'scheduled_block_minutes', 'scheduled_duty_minutes', 'trips'] as $field) {
                $totals[$window][$field] = array_sum(array_map(fn (array $row): int => $row['hours'][$window][$field], $rows));
            }
        }

        return ApiResponse::resource(JsonResource::make([...$this->hours->meta(), 'group' => $group, 'rows' => $rows, 'totals' => $totals]));
    }

    /**
     * GET /my-hours — the caller's own hours (pilot and cabin crew accounts); 404 without a crew profile.
     */
    public function mine(Request $request): JsonResponse
    {
        Gate::authorize('read-rosters');
        abort_unless($request->user()->crew_member_id, 404);
        $crew = CrewMember::query()->findOrFail($request->user()->crew_member_id);

        return ApiResponse::resource(JsonResource::make([...$this->hours->meta(), 'crew' => $this->crew($crew), 'hours' => $this->hours->summaries(collect([$crew]))[$crew->id]]));
    }

    /**
     * Crew fields shown beside the hours.
     *
     * @return array<string, mixed>
     */
    private function crew(CrewMember $crew): array
    {
        return ['id' => $crew->id, 'name' => $crew->name, 'rank' => $crew->rank, 'base_airport' => $crew->base_airport, 'weekly_hours' => $crew->weekly_hours, 'active' => $crew->active];
    }
}
