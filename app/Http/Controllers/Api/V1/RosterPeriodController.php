<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RosterPeriodRequest;
use App\Http\Requests\RosterQueryRequest;
use App\Http\Resources\RosterPeriodResource;
use App\Http\Resources\RosterWeekResource;
use App\Models\Assignment;
use App\Models\RosterPeriod;
use App\Models\User;
use App\Services\RosterBuilderService;
use App\Services\RosterConflictService;
use App\Services\RosterLegalityService;
use App\Services\RosterPeriodService;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/roster-periods — weekly rosters: the week timeline, the roster window for one week, and building,
 * publishing and reopening a week. Crew see only published weeks and only their own seats.
 */
class RosterPeriodController extends Controller
{
    public function __construct(private RosterPeriodService $periods, private RosterLegalityService $legality, private RosterConflictService $conflicts) {}

    /**
     * GET /roster-periods?from=YYYY-MM-DD&to=YYYY-MM-DD — periods (weeks, fortnights, months) overlapping the
     * range (default: eight weeks back to twelve ahead), oldest first. Staff also get trip and seat counts;
     * crew only see published periods.
     */
    public function index(RosterQueryRequest $request): JsonResponse
    {
        $staff = $request->user()->isStaff();
        $monday = CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY);
        $from = $request->validated('from') ?? $monday->subWeeks(8)->format('Y-m-d');
        $to = $request->validated('to') ?? $monday->addWeeks(12)->format('Y-m-d');
        $seats = fn (bool $open) => Assignment::query()->selectRaw('count(*)')->join('trips', 'trips.id', '=', 'assignments.trip_id')
            ->whereColumn('trips.roster_period_id', 'roster_periods.id')->when($open, fn ($query) => $query->whereNull('assignments.crew_member_id'));
        $periods = RosterPeriod::query()->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)
            ->when(! $staff, fn ($query) => $query->where('status', 'published'))
            ->when($staff, fn ($query) => $query->select('roster_periods.*')->withCount('trips')->addSelect(['seats_count' => $seats(false), 'open_seats_count' => $seats(true)]))
            ->orderBy('starts_on')->get();

        return ApiResponse::resource(RosterPeriodResource::collection($periods));
    }

    /**
     * POST /roster-periods {starts_on, length: week|fortnight|month} — 201 for a new draft period, 200 when
     * the same period already exists; 422 when it would overlap another roster.
     */
    public function store(RosterPeriodRequest $request): JsonResponse
    {
        $period = $this->periods->create($request->validated('starts_on'), $request->user(), $request->validated('length'));
        $label = ['label' => $period->label()];

        return $period->wasRecentlyCreated
            ? ApiResponse::created(new RosterPeriodResource($period), 'roster_period.created', $label)
            : ApiResponse::resource(new RosterPeriodResource($period), 'roster_period.exists', $label);
    }

    /**
     * GET /roster-periods/{id} — the roster window for one week. A crew account asking for an unpublished
     * week gets 404, so drafts are not even confirmed to exist.
     */
    public function show(Request $request, RosterPeriod $rosterPeriod): JsonResponse
    {
        Gate::authorize('read-rosters');
        abort_if(! $request->user()->isStaff() && $rosterPeriod->status !== 'published', 404);

        return ApiResponse::resource($this->week($rosterPeriod, $request->user()));
    }

    /**
     * POST /roster-periods/{id}/build — run the roster generator for a draft roster (1 week, 2 weeks or a month)
     * and return the new roster. A monthly build checks every crew member for hundreds of seats, so the request
     * asks PHP for more than the usual 30 seconds (config roster.build_time_limit) where the host allows it.
     */
    public function build(Request $request, RosterPeriod $rosterPeriod, RosterBuilderService $builder): JsonResponse
    {
        Gate::authorize('manage-operations');
        // Only raise an existing limit (0 means none, as on the command line); hosts may disable set_time_limit.
        if ((int) ini_get('max_execution_time') !== 0 && function_exists('set_time_limit')) {
            set_time_limit((int) config('roster.build_time_limit'));
        }
        $result = $builder->build($rosterPeriod, $request->user());
        // Explain the outcome: nothing to plan, open seats with their main reasons, or everything filled.
        $key = match (true) {
            $result['planned_trips'] === 0 => 'roster_period.built_empty',
            $result['open'] > 0 => 'roster_period.built_with_open',
            default => 'roster_period.built',
        };
        $reasons = collect($result['reasons'])->map(fn (int $count, string $code): string => (RosterLegalityService::REASON_LABELS[$code] ?? $code).' ('.$count.')')->implode(', ');

        return ApiResponse::resource($this->week($rosterPeriod->refresh(), $request->user(), $result), $key, [
            'label' => $rosterPeriod->label(), 'filled' => $result['filled'], 'seats' => $result['seats'], 'open' => $result['open'], 'reasons' => $reasons ?: 'see each open seat',
        ]);
    }

    /**
     * POST /roster-periods/{id}/publish — release a built week to crew (422 while rule conflicts remain).
     */
    public function publish(Request $request, RosterPeriod $rosterPeriod): JsonResponse
    {
        Gate::authorize('manage-operations');
        $period = $this->periods->publish($rosterPeriod, $request->user());

        return ApiResponse::resource($this->week($period, $request->user()), 'roster_period.published', ['label' => $period->label()]);
    }

    /**
     * POST /roster-periods/{id}/reopen — take a published week back to draft for changes.
     */
    public function reopen(Request $request, RosterPeriod $rosterPeriod): JsonResponse
    {
        Gate::authorize('manage-operations');
        $period = $this->periods->reopen($rosterPeriod, $request->user());

        return ApiResponse::resource($this->week($period, $request->user()), 'roster_period.reopened', ['label' => $period->label()]);
    }

    /**
     * The roster window payload. Staff get every seat, conflicts and seat totals; a crew account gets only
     * their own seats with their own duty totals.
     *
     * @param  array<string, mixed>|null  $build  generator result to pass back after a build
     */
    private function week(RosterPeriod $period, User $user, ?array $build = null): RosterWeekResource
    {
        $context = $this->legality->context($period);
        if ($user->isStaff()) {
            $conflicts = $this->conflicts->forPeriod($period, $context);

            return new RosterWeekResource(['context' => $context, 'conflicts' => $conflicts, 'summary' => [...$this->conflicts->summary($context, $conflicts), 'build' => $build], 'crew_member_id' => null]);
        }
        $own = (int) $user->crew_member_id;
        [$from, $to] = [$period->starts_on->format('Y-m-d'), $period->ends_on->format('Y-m-d')];

        return new RosterWeekResource(['context' => $context, 'conflicts' => [], 'crew_member_id' => $own, 'summary' => [
            'duties' => $context->trips->filter(fn ($trip): bool => $trip->assignments->contains('crew_member_id', $own))->count(),
            'duty_minutes' => $context->schedule($own)->minutesBetween($from, $to),
            'block_minutes' => $context->schedule($own)->blockMinutesBetween($from, $to),
        ]]);
    }
}
