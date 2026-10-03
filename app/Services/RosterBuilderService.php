<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CrewActivity;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\RosterPeriod;
use App\Models\RuleSet;
use App\Models\Trip;
use App\Models\User;
use App\Support\Roster\CrewSchedule;
use App\Support\Roster\Duty;
use App\Support\Roster\PlanningContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The roster generator. For one draft period (a week, two weeks or a month) it:
 *
 *  1. expands every enabled flight pattern into dated trips for its operating days (with a UTC schedule
 *     snapshot) and makes sure each trip has its CPT, FO and cabin seats;
 *  2. releases the automatic seats from earlier builds, keeping every manual (locked) seat;
 *  3. fills the hardest seats first (fewest eligible crew), giving each to the legal crew member with the
 *     lowest fairness score, mostly the smallest share of their weekly working hours already used;
 *  4. never breaks a rule: a seat with no legal candidate stays open, with the reasons recorded;
 *  5. rebalances: moves automatic seats from the busiest crew to legal crew with a smaller share of their
 *     working hours while that narrows the gap;
 *  6. plans standby on free days for crew who would otherwise exceed the maximum weekly days off (prorated
 *     for leave and part weeks), only when legal. Generated standby is replaced on the next build.
 *
 * Trips that started before today are left exactly as they are. The run is one transaction under a row
 * lock on the week, and is deterministic: the same data always gives the same roster.
 */
class RosterBuilderService
{
    public function __construct(
        private AuditService $audit,
        private ExpiryService $expiry,
        private FlightTimelineService $timeline,
        private RosterLegalityService $legality,
        private RosterPeriodService $periods,
        private CrewActivityService $activities,
    ) {}

    /**
     * Build (or rebuild) a draft week and return what happened.
     *
     * @return array{trips: int, planned_trips: int, seats: int, filled: int, open: int, kept: int, rebalanced: int, standby: int, skipped: array<int, string>, reasons: array<string, int>}
     *
     * @throws ValidationException when the week is published or has ended
     */
    public function build(RosterPeriod $period, User $actor): array
    {
        return DB::transaction(function () use ($period, $actor): array {
            $period = RosterPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $this->periods->assertEditable($period);
            $today = $this->expiry->today();
            $skipped = $this->expand($period, $today);
            // Standby planned by an earlier build is replaced; standby entered by people is never touched.
            CrewActivity::query()->where('roster_period_id', $period->id)->where('date', '>=', $today->format('Y-m-d'))->delete();

            $context = $this->legality->context($period);
            $open = [];
            $kept = 0;
            foreach ($context->trips as $trip) {
                if ($context->operated($trip)) {
                    continue;
                }
                foreach ($trip->assignments as $seat) {
                    if ($seat->source === 'manual' && $seat->crew_member_id !== null) {
                        $kept++;

                        continue;
                    }
                    // Release the seat from any earlier automatic build; it is planned again below.
                    if ($seat->crew_member_id !== null) {
                        $context->schedule($seat->crew_member_id)->remove('assignment:'.$seat->id);
                    }
                    $seat->forceFill(['crew_member_id' => null, 'source' => 'auto', 'flag_reasons' => null, 'decision_log' => null]);
                    // Saved empty now so refilling can never collide with the one-seat-per-trip unique index.
                    if ($seat->isDirty()) {
                        $seat->save();
                    }
                    $open[] = ['trip' => $trip, 'seat' => $seat, 'eligible' => $this->eligibleCount($context, $trip, $seat)];
                }
            }
            // Hardest seats first, then in time order so ties fill the earliest duties first.
            usort($open, fn (array $a, array $b): int => [$a['eligible'], $a['trip']->start_date->format('Y-m-d'), $a['trip']->schedule_snapshot['report'] ?? '', array_search($a['seat']->rank, ['CPT', 'FO', 'CC'], true), $a['seat']->seat_number, $a['seat']->id]
                <=> [$b['eligible'], $b['trip']->start_date->format('Y-m-d'), $b['trip']->schedule_snapshot['report'] ?? '', array_search($b['seat']->rank, ['CPT', 'FO', 'CC'], true), $b['seat']->seat_number, $b['seat']->id]);
            foreach ($open as $item) {
                $this->fill($context, $item['trip'], $item['seat']);
            }
            $rebalanced = $this->rebalance($context);
            foreach ($context->trips as $trip) {
                foreach ($trip->assignments as $seat) {
                    if ($seat->isDirty()) {
                        $seat->save();
                    }
                }
            }

            $standby = $this->planStandby($context, $today);

            $seats = $context->trips->sum(fn (Trip $trip): int => $trip->assignments->count());
            $filled = $context->trips->sum(fn (Trip $trip): int => $trip->assignments->whereNotNull('crew_member_id')->count());
            // Why seats stayed open: rejection counts summed over the open seats planned in this build.
            $reasons = [];
            foreach ($open as $item) {
                if ($item['seat']->crew_member_id === null) {
                    foreach ($item['seat']->decision_log['reason_counts'] ?? [] as $code => $count) {
                        $reasons[$code] = ($reasons[$code] ?? 0) + $count;
                    }
                }
            }
            arsort($reasons);
            $summary = ['trips' => $context->trips->count(), 'planned_trips' => $context->trips->reject(fn (Trip $trip): bool => $context->operated($trip))->count(), 'seats' => $seats, 'filled' => $filled, 'open' => $seats - $filled,
                'kept' => $kept, 'rebalanced' => $rebalanced, 'standby' => $standby, 'skipped' => $skipped, 'reasons' => array_slice($reasons, 0, 3, true)];
            $before = $period->toArray();
            $period->built_at = now();
            $period->built_by = $actor->id;
            $period->save();
            $this->audit->record($actor, 'built', $period, $before, [...$period->toArray(), 'summary' => $summary]);

            return $summary;
        });
    }

    /**
     * Turn a validated flight pattern into a dated schedule snapshot: each duty period's report and release,
     * and each leg's times, as UTC instants plus their base-local clock times and dates. The offset comes
     * from the week's rules snapshot, never from the server or browser timezone.
     *
     * @param  array{base: string, periods: array<int, array<string, mixed>>, warnings: array<int, string>}  $timeline  FlightTimelineService::validate() result
     * @return array<string, mixed>
     */
    public function snapshot(Flight $flight, array $timeline, CarbonImmutable $date, int $offset): array
    {
        $day = $date->format('Y-m-d');
        $instant = fn (int $minutes): string => $this->timeline->instant($day, $minutes, $offset);
        $localDate = fn (int $minutes): string => $date->addDays((int) floor($minutes / 1440))->format('Y-m-d');
        $clock = fn (int $minutes): string => sprintf('%02d:%02d', intdiv((($minutes % 1440) + 1440) % 1440, 60), (($minutes % 60) + 60) % 60);
        $duties = [];
        $route = [$timeline['base']];
        foreach ($timeline['periods'] as $period) {
            $dates = [];
            for ($at = $date->addDays((int) floor($period['report_min'] / 1440)); $at->format('Y-m-d') <= $localDate($period['release_min'] - 1); $at = $at->addDay()) {
                $dates[] = $at->format('Y-m-d');
            }
            $legs = [];
            foreach ($period['legs'] as $leg) {
                $route[] = $leg['to_airport'];
                $legs[] = [
                    'from_airport' => $leg['from_airport'], 'to_airport' => $leg['to_airport'],
                    'departs' => $instant($leg['departure_min']), 'arrives' => $instant($leg['arrival_min']),
                    'departs_local' => $clock($leg['departure_min']), 'arrives_local' => $clock($leg['arrival_min']),
                    'block_minutes' => $leg['arrival_min'] - $leg['departure_min'],
                ];
            }
            $duties[] = [
                'trip_day' => $period['trip_day'],
                'date' => $localDate($period['report_min']),
                'dates' => $dates,
                'report' => $instant($period['report_min']), 'release' => $instant($period['release_min']),
                'report_local' => $clock($period['report_min']), 'release_local' => $clock($period['release_min']),
                'block_minutes' => $period['block_min'],
                'duty_minutes' => $period['release_min'] - $period['report_min'],
                'legs' => $legs,
            ];
        }

        return [
            'code' => $flight->code,
            'flight_id' => $flight->id,
            'aircraft_type_id' => $flight->aircraft_type_id,
            'aircraft_type' => $flight->aircraftType->code,
            'palette' => $flight->aircraftType->palette,
            'cabin_crew_required' => $flight->aircraftType->cabin_crew_required,
            'base' => $timeline['base'],
            'route' => implode('-', $route),
            'utc_offset_minutes' => $offset,
            'report' => $duties[0]['report'],
            'release' => $duties[array_key_last($duties)]['release'],
            'block_minutes' => array_sum(array_column($duties, 'block_minutes')),
            'duty_minutes' => array_sum(array_column($duties, 'duty_minutes')),
            'duties' => $duties,
            'warnings' => $timeline['warnings'],
        ];
    }

    /**
     * Create, refresh or remove the week's trips from today onwards so they match the enabled patterns, and
     * give every trip its required seats. Trips holding manual seats are kept even if their pattern was
     * disabled (the conflict check reports them). Patterns that no longer pass the week's frozen rules are
     * skipped and reported.
     *
     * @return array<int, string> messages for skipped patterns
     */
    private function expand(RosterPeriod $period, CarbonImmutable $today): array
    {
        $rules = new RuleSet($period->rules_snapshot);
        $offset = (int) $period->rules_snapshot['utc_offset_minutes'];
        $existing = $period->trips()->with('assignments')->get()->keyBy(fn (Trip $trip): string => $trip->flight_id.'|'.$trip->start_date->format('Y-m-d'));
        $wanted = [];
        $skipped = [];
        foreach (Flight::query()->where('active', true)->with(['legs', 'days', 'aircraftType'])->orderBy('code')->get() as $flight) {
            if ($flight->legs->isEmpty()) {
                continue;
            }
            try {
                $timeline = $this->timeline->validate($flight->legs->map(fn ($leg): array => $leg->only(['trip_day', 'from_airport', 'to_airport', 'departs_local', 'arrives_local']))->all(), $rules);
            } catch (ValidationException $exception) {
                $skipped[] = $flight->code.': '.collect($exception->errors())->flatten()->first();

                continue;
            }
            // Every date of the period (a week, two weeks or a month) on which the pattern operates (Monday = 0).
            $weekdays = $flight->days->pluck('weekday')->all();
            foreach ($period->dates() as $day) {
                $date = CarbonImmutable::parse($day, 'UTC');
                if ($date->lt($today) || ! in_array($date->dayOfWeekIso - 1, $weekdays, true)) {
                    continue;
                }
                $key = $flight->id.'|'.$date->format('Y-m-d');
                $wanted[$key] = true;
                $trip = $existing->get($key) ?? new Trip(['roster_period_id' => $period->id, 'flight_id' => $flight->id, 'start_date' => $date->format('Y-m-d')]);
                $trip->schedule_snapshot = $this->snapshot($flight, $timeline, $date, $offset);
                $trip->save();
                $this->ensureSeats($trip, $flight->aircraftType->cabin_crew_required);
            }
        }
        foreach ($existing as $key => $trip) {
            $locked = $trip->assignments->contains(fn (Assignment $seat): bool => $seat->source === 'manual' && $seat->crew_member_id !== null);
            if (! isset($wanted[$key]) && $trip->start_date->gte($today) && ! $locked) {
                $trip->delete();
            }
        }

        return $skipped;
    }

    /**
     * One CPT seat, one FO seat and one numbered cabin seat per required cabin crew member. Surplus seats
     * (after the cabin requirement went down) are removed unless a manual assignment holds them.
     */
    private function ensureSeats(Trip $trip, int $cabin): void
    {
        $wanted = ['CPT' => 1, 'FO' => 1, 'CC' => $cabin];
        $current = $trip->exists ? $trip->assignments()->get() : collect();
        foreach ($wanted as $rank => $count) {
            for ($number = 1; $number <= $count; $number++) {
                if (! $current->contains(fn (Assignment $seat): bool => $seat->rank === $rank && $seat->seat_number === $number)) {
                    $trip->assignments()->create(['rank' => $rank, 'seat_number' => $number, 'source' => 'auto']);
                }
            }
        }
        foreach ($current as $seat) {
            if ($seat->seat_number > $wanted[$seat->rank] && ! ($seat->source === 'manual' && $seat->crew_member_id !== null)) {
                $seat->delete();
            }
        }
    }

    /** How many crew could hold the seat ignoring other duties (used to fill the hardest seats first). */
    private function eligibleCount(PlanningContext $context, Trip $trip, Assignment $seat): int
    {
        return $context->crews->filter(fn ($crew): bool => $crew->rank === $seat->rank && $crew->active && $this->legality->eligibility($context, $crew, $trip, $seat->rank) === [])->count();
    }

    /**
     * Give the seat to the legal crew member with the lowest fairness score (ties: fewer hours this week,
     * then name and id), or leave it open. Either way the decision log records the alternatives and the
     * reasons others were rejected, so the scheduler can see why.
     */
    private function fill(PlanningContext $context, Trip $trip, Assignment $seat): void
    {
        $duty = $context->tripDuties[$trip->id] ?? null;
        if ($duty === null) {
            return;
        }
        $legal = [];
        $rejected = [];
        $reasons = [];
        foreach ($context->crews as $crew) {
            if ($crew->rank !== $seat->rank || ! $crew->active) {
                continue;
            }
            $issues = $this->legality->issues($context, $crew, $trip, $seat);
            if ($issues === []) {
                $legal[] = ['crew' => $crew, ...$this->legality->score($context, $crew, $trip, $seat)];

                continue;
            }
            $rejected[] = ['crew_member_id' => $crew->id, 'name' => $crew->name, 'reason' => $issues[0]['message']];
            foreach (array_unique(array_column($issues, 'code')) as $code) {
                $reasons[$code] = ($reasons[$code] ?? 0) + 1;
            }
        }
        usort($legal, fn (array $a, array $b): int => [$a['score'], $a['week_minutes'], $a['crew']->name, $a['crew']->id] <=> [$b['score'], $b['week_minutes'], $b['crew']->name, $b['crew']->id]);
        arsort($reasons);
        $log = ['by' => 'generator', 'at' => now()->toIso8601String(), 'considered' => count($legal) + count($rejected), 'legal' => count($legal), 'reason_counts' => $reasons, 'rejected' => array_slice($rejected, 0, 6)];
        if ($legal === []) {
            $seat->decision_log = $log;

            return;
        }
        $chosen = $legal[0];
        $seat->crew_member_id = $chosen['crew']->id;
        $seat->decision_log = [...$log,
            'score' => $chosen['score'], 'week_minutes' => $chosen['week_minutes'] + $duty->dutyMinutes(), 'capacity_minutes' => $chosen['capacity_minutes'],
            'month_block_minutes' => $chosen['month_block_minutes'], 'month_trips' => $chosen['month_trips'],
            'alternatives' => array_map(fn (array $item): array => ['crew_member_id' => $item['crew']->id, 'name' => $item['crew']->name, 'score' => $item['score']], array_slice($legal, 1, 3)),
        ];
        // The chosen duty now counts against every later decision in this build.
        $context->schedule($chosen['crew']->id)->add($duty->withKey('assignment:'.$seat->id));
    }

    /**
     * Even out workload after the greedy fill: up to three passes over the automatic seats, busiest holder
     * first, each seat moving to the legal crew member with the lowest share of their capacity for the
     * period (weekly capacity × weeks in the period) when the move lowers the larger of the two shares.
     * Returns how many seats moved.
     */
    private function rebalance(PlanningContext $context): int
    {
        [$from, $to] = [$context->period->starts_on->format('Y-m-d'), $context->period->ends_on->format('Y-m-d')];
        $weeks = count($context->period->dates()) / 7;
        $share = fn (CrewMember $crew, int $extra = 0): float => ($context->schedule($crew->id)->minutesBetween($from, $to) + $extra) / max($this->legality->capacity($context, $crew) * $weeks, 1);
        $moves = 0;
        for ($pass = 0; $pass < 3; $pass++) {
            $seats = [];
            foreach ($context->trips as $trip) {
                if ($context->operated($trip) || ($context->tripDuties[$trip->id] ?? null) === null) {
                    continue;
                }
                foreach ($trip->assignments as $seat) {
                    if ($seat->source === 'auto' && $seat->crew_member_id !== null) {
                        $seats[] = [$trip, $seat, $share($context->crews->get($seat->crew_member_id))];
                    }
                }
            }
            // Busiest holders first; seat id keeps the order deterministic.
            usort($seats, fn (array $a, array $b): int => [$b[2], $a[1]->id] <=> [$a[2], $b[1]->id]);
            $movedThisPass = 0;
            foreach ($seats as [$trip, $seat]) {
                $holder = $context->crews->get($seat->crew_member_id);
                $duty = $context->tripDuties[$trip->id];
                $minutes = $duty->dutyMinutes();
                $holderShare = $share($holder);
                $holderAfter = $share($holder, -$minutes);
                $others = $context->crews->filter(fn (CrewMember $crew): bool => $crew->rank === $seat->rank && $crew->active && $crew->id !== $holder->id)
                    ->sortBy(fn (CrewMember $crew): array => [$share($crew), $crew->id]);
                foreach ($others as $crew) {
                    if ($share($crew) >= $holderShare || max($holderAfter, $share($crew, $minutes)) >= $holderShare - 0.0001) {
                        continue;
                    }
                    if ($this->legality->issues($context, $crew, $trip, $seat) !== []) {
                        continue;
                    }
                    $context->schedule($holder->id)->remove('assignment:'.$seat->id);
                    $context->schedule($crew->id)->add($duty->withKey('assignment:'.$seat->id));
                    $seat->crew_member_id = $crew->id;
                    $seat->decision_log = [...($seat->decision_log ?? []), 'rebalanced_from' => ['crew_member_id' => $holder->id, 'name' => $holder->name]];
                    $movedThisPass++;

                    break;
                }
            }
            $moves += $movedThisPass;
            if ($movedThisPass === 0) {
                break;
            }
        }

        return $moves;
    }

    /**
     * Plan standby on free days (from today) for active crew who would otherwise have more days off in a
     * Monday–Sunday week than the rules allow. Each week of the period is handled on its own; the allowance is
     * prorated: max_days_off_week × available days / 7, where leave days are not available. Standby is only
     * planned on days when flights leave the crew member's base (it is a reserve for flying days), never on
     * protected days off, and only when legal (rest, limits, working hours). A max_days_off_week of 7 turns
     * this off. Returns how many standby days were planned.
     */
    private function planStandby(PlanningContext $context, CarbonImmutable $today): int
    {
        $maxOff = (int) $context->rules['max_days_off_week'];
        if ($maxOff >= 7) {
            return 0;
        }
        $offset = (int) $context->rules['utc_offset_minutes'];
        $start = (string) config('roster.standby_start_local');
        $startMinutes = (int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2) + (int) config('roster.standby_hours') * 60;
        $end = sprintf('%02d:%02d', intdiv($startMinutes % 1440, 60), $startMinutes % 60);
        // Remaining days of the period, grouped by Monday-week, and the days flights leave each base.
        $weeks = collect($context->period->dates())->filter(fn (string $date): bool => $date >= $today->format('Y-m-d'))->groupBy(fn (string $date): string => Duty::weekOf($date));
        $flyingDays = [];
        foreach ($context->trips as $trip) {
            $flyingDays[$trip->schedule_snapshot['base'] ?? ''][$trip->start_date->format('Y-m-d')] = true;
        }
        $planned = 0;
        foreach ($context->crews as $crew) {
            if (! $crew->active) {
                continue;
            }
            $schedule = $context->schedule($crew->id);
            $activities = $crew->activities->keyBy(fn (CrewActivity $activity): string => $activity->date->format('Y-m-d'));
            foreach ($weeks as $days) {
                $planned += $this->standbyForWeek($context, $crew, $schedule, $activities, $days->values()->all(), $flyingDays[$crew->base_airport] ?? [], $maxOff, $start, $end, $offset);
            }
        }

        return $planned;
    }

    /**
     * Standby for one crew member in one week of the period (see planStandby()).
     *
     * @param  Collection<string, CrewActivity>  $activities  keyed by date
     * @param  array<int, string>  $days  remaining dates of this week inside the period
     * @param  array<string, bool>  $flyingDays  dates with flights from the crew member's base
     */
    private function standbyForWeek(PlanningContext $context, CrewMember $crew, CrewSchedule $schedule, Collection $activities, array $days, array $flyingDays, int $maxOff, string $start, string $end, int $offset): int
    {
        $planned = 0;
        $dutyDates = array_flip(array_merge([], ...array_map(fn (Duty $duty): array => $duty->dates(), array_values($schedule->duties))));
        $available = count(array_filter($days, fn (string $date): bool => $activities->get($date)?->type !== 'leave'));
        if ($available === 0) {
            return 0;
        }
        $free = array_values(array_filter($days, fn (string $date): bool => ! isset($dutyDates[$date]) && ! $activities->has($date)));
        $protected = count(array_filter($days, fn (string $date): bool => $activities->get($date)?->type === 'day_off'));
        $excess = count($free) + $protected - (int) round($maxOff * $available / 7);
        foreach (array_filter($free, fn (string $date): bool => isset($flyingDays[$date])) as $date) {
            if ($excess <= 0) {
                break;
            }
            [$startsAt, $endsAt] = $this->activities->instants(CarbonImmutable::parse($date, 'UTC'), $start, $end, $offset);
            $duty = new Duty('standby:'.$date, 'Standby on '.CarbonImmutable::parse($date)->format('d M'), null, [[
                'start' => intdiv(CarbonImmutable::parse($startsAt, 'UTC')->getTimestamp(), 60),
                'end' => intdiv(CarbonImmutable::parse($endsAt, 'UTC')->getTimestamp(), 60),
                'block' => 0, 'date' => $date, 'dates' => [$date],
            ]]);
            if ($this->legality->dutyIssues($context, $crew, $duty) !== []) {
                continue;
            }
            $activity = new CrewActivity(['crew_member_id' => $crew->id, 'date' => $date, 'type' => 'standby', 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'note' => 'Planned standby (workload)']);
            $activity->roster_period_id = $context->period->id;
            $activity->save();
            $schedule->add($duty->withKey('activity:'.$activity->id));
            $excess--;
            $planned++;
        }

        return $planned;
    }
}
