<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CrewActivity;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\RosterPeriod;
use App\Models\Trip;
use App\Models\User;
use App\Support\Roster\CrewSchedule;
use App\Support\Roster\Duty;
use App\Support\Roster\PlanningContext;
use App\Support\Roster\Section;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The roster generator. For one draft period (a week, two weeks or a month) it:
 *
 *  1. expands every enabled flight pattern into dated trips for its operating days (RosterScheduleService,
 *     with a UTC schedule snapshot), removes trips the routes no longer call for, and makes sure each trip
 *     has its CPT, FO and cabin seats;
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
 *
 * A rebuild after flights were disabled, enabled or edited (RosterScheduleService::changes()) only runs when
 * the caller confirms the exact route changes it reviewed, because it can remove trips and release crew,
 * locked seats included.
 */
class RosterBuilderService
{
    public function __construct(
        private AuditService $audit,
        private ExpiryService $expiry,
        private RosterScheduleService $schedule,
        private RosterLegalityService $legality,
        private RosterPeriodService $periods,
        private CrewActivityService $activities,
    ) {}

    /**
     * Build (or rebuild) a draft week and return what happened.
     *
     * @param  string|null  $confirmedChanges  fingerprint of the route changes the scheduler reviewed and accepted
     *                                         (RosterScheduleService::changes()); not needed for a first build or
     *                                         when the routes have not changed since the last one
     * @return array{trips: int, planned_trips: int, seats: int, filled: int, open: int, kept: int, rebalanced: int, standby: int, skipped: array<int, string>, reasons: array<string, int>, route_changes: array{added: int, removed: int, changed: int, released_seats: int}|null}
     *
     * @throws ValidationException when the week is published or has ended, or route changes were not confirmed
     */
    public function build(RosterPeriod $period, User $actor, ?string $confirmedChanges = null): array
    {
        return DB::transaction(function () use ($period, $actor, $confirmedChanges): array {
            $period = RosterPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $this->periods->assertEditable($period);
            $today = $this->expiry->today();
            $plan = $this->schedule->plan($period, $today);
            // Removing trips releases crew (locked seats too), so the scheduler must have seen exactly this list.
            $changes = $this->schedule->changes($period, $today, $plan);
            if ($changes !== null && $changes['count'] > 0 && ! hash_equals($changes['fingerprint'], (string) $confirmedChanges)) {
                throw ValidationException::withMessages(['route_changes' => 'Flight routes have changed since this roster was built. Review the route changes and confirm them to rebuild.']);
            }
            $skipped = $this->expand($period, $today, $plan);
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
                'kept' => $kept, 'rebalanced' => $rebalanced, 'standby' => $standby, 'skipped' => $skipped, 'reasons' => array_slice($reasons, 0, 3, true),
                'route_changes' => $changes !== null && $changes['count'] > 0 ? ['added' => count($changes['added']), 'removed' => count($changes['removed']), 'changed' => count($changes['changed']), 'released_seats' => $changes['released_seats']] : null];
            $before = $period->toArray();
            $period->built_at = now();
            $period->built_by = $actor->id;
            $period->save();
            // The audit keeps the full list of applied route changes, including every crew member released.
            $applied = $summary['route_changes'] !== null ? ['route_changes' => Arr::except($changes, ['count', 'fingerprint'])] : [];
            $this->audit->record($actor, 'built', $period, $before, [...$period->toArray(), 'summary' => $summary, ...$applied]);

            return $summary;
        });
    }

    /**
     * Create, refresh or remove the roster's trips from today onwards so they match the enabled patterns
     * (RosterScheduleService::plan()), and give every trip its required seats. Trips the routes no longer call
     * for (flight disabled, not operating that day, or failing the roster's frozen rules) are removed with
     * their seats, locked ones included: build() only gets here once the scheduler has confirmed that list.
     *
     * @param  array{trips: array<string, array{flight: Flight, date: string, snapshot: array<string, mixed>, aircraft_id: ?int, aircraft_source: string}>, skipped: array<int, string>, invalid: array<int, string>}  $plan
     * @return array<int, string> messages for skipped patterns
     */
    private function expand(RosterPeriod $period, CarbonImmutable $today, array $plan): array
    {
        $existing = $period->trips()->get()->keyBy(fn (Trip $trip): string => $trip->flight_id.'|'.$trip->start_date->format('Y-m-d'));
        foreach ($plan['trips'] as $key => $wanted) {
            $trip = $existing->get($key) ?? new Trip(['roster_period_id' => $period->id, 'flight_id' => $wanted['flight']->id, 'start_date' => $wanted['date']]);
            // Captain sections are leg ranges, so they only survive while the trip keeps the same legs.
            $legsChanged = $trip->exists && Section::signature($trip->schedule_snapshot ?? []) !== Section::signature($wanted['snapshot']);
            $trip->schedule_snapshot = $wanted['snapshot'];
            // The airframe the plan chose (or crew control's own choice, which the plan keeps).
            $trip->forceFill(['aircraft_id' => $wanted['aircraft_id'] ?? null, 'aircraft_source' => $wanted['aircraft_source'] ?? 'auto']);
            $trip->save();
            $this->ensureSeats($trip, (int) $wanted['snapshot']['cabin_crew_required'], $legsChanged);
        }
        foreach ($existing as $key => $trip) {
            if (! isset($plan['trips'][$key]) && $trip->start_date->gte($today)) {
                $trip->delete();
            }
        }

        return $plan['skipped'];
    }

    /**
     * One FO seat and one numbered cabin seat per required cabin crew member, plus the captain seat(s): one for
     * the whole trip, or the sections crew control split it into. Surplus cabin seats (after the cabin
     * requirement went down) are removed unless a manual assignment holds them.
     *
     * Captain sections are kept while they still cover the trip's legs in order. When the flight pattern's
     * legs changed ($legsChanged) or the sections no longer fit, the trip goes back to one captain for the whole
     * trip and the section captains are released (the route-change review listed them before the rebuild).
     */
    private function ensureSeats(Trip $trip, int $cabin, bool $legsChanged = false): void
    {
        $wanted = ['CPT' => 1, 'FO' => 1, 'CC' => $cabin];
        $current = $trip->exists ? $trip->assignments()->get() : collect();
        $captains = $current->where('rank', 'CPT')->sortBy('seat_number')->values();
        $split = $captains->count() > 1 || $captains->contains(fn (Assignment $seat): bool => $seat->from_leg !== null);
        if ($split && ($legsChanged || ! $this->sectionsFit($captains, Section::count($trip->schedule_snapshot)))) {
            foreach ($captains as $seat) {
                if ($seat->seat_number > 1) {
                    $seat->delete();

                    continue;
                }
                $seat->forceFill(['from_leg' => null, 'to_leg' => null, 'crew_member_id' => null, 'source' => 'auto', 'flag_reasons' => null,
                    'decision_log' => ['by' => 'generator', 'at' => now()->toIso8601String(), 'action' => 'sections_reset', 'reason' => 'The flight pattern\'s legs changed, so the captain sections were joined back into one seat.']])->save();
            }
            $current = $trip->assignments()->get();
        }
        foreach ($wanted as $rank => $count) {
            for ($number = 1; $number <= $count; $number++) {
                if (! $current->contains(fn (Assignment $seat): bool => $seat->rank === $rank && $seat->seat_number === $number)) {
                    $trip->assignments()->create(['rank' => $rank, 'seat_number' => $number, 'source' => 'auto']);
                }
            }
        }
        foreach ($current as $seat) {
            // Captain sections are numbered seats too; only cabin seats follow the aircraft's requirement.
            if ($seat->rank !== 'CPT' && $seat->seat_number > $wanted[$seat->rank] && ! ($seat->source === 'manual' && $seat->crew_member_id !== null)) {
                $seat->delete();
            }
        }
    }

    /**
     * Bring a trip's seats in line with its snapshot after a manual change of plane: cabin seats follow the new
     * plane's type (extra open seats, or surplus unlocked ones removed); captain sections are kept.
     */
    public function syncSeats(Trip $trip): void
    {
        $this->ensureSeats($trip, (int) ($trip->schedule_snapshot['cabin_crew_required'] ?? 0));
    }

    /**
     * Whether captain seats, in seat order, cover legs 1…$legs one after another without gaps or overlaps.
     *
     * @param  Collection<int, Assignment>  $captains  sorted by seat_number
     */
    private function sectionsFit(Collection $captains, int $legs): bool
    {
        $next = 1;
        foreach ($captains as $seat) {
            if ($seat->from_leg !== $next || $seat->to_leg === null || $seat->to_leg < $seat->from_leg) {
                return false;
            }
            $next = $seat->to_leg + 1;
        }

        return $next === $legs + 1;
    }

    /**
     * How many crew could hold the seat ignoring other duties (used to fill the hardest seats first). The
     * generator never plans positioning, so for a captain section only captains based at both ends count.
     */
    private function eligibleCount(PlanningContext $context, Trip $trip, Assignment $seat): int
    {
        return $context->crews->filter(fn ($crew): bool => $crew->rank === $seat->rank && $crew->active && $this->legality->eligibility($context, $crew, $trip, $seat->rank, $seat) === []
            && $this->legality->positioning($crew, $trip, $seat) === null)->count();
    }

    /**
     * Give the seat to the legal crew member with the lowest fairness score (ties: fewer hours this week,
     * then name and id), or leave it open. Either way the decision log records the alternatives and the
     * reasons others were rejected, so the scheduler can see why.
     */
    private function fill(PlanningContext $context, Trip $trip, Assignment $seat): void
    {
        // A captain section is filled as the duty of that section only.
        $duty = $context->seatDuty($trip, $seat);
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
            // Positioning is crew control's call (a warning when they choose it), never the generator's.
            $positioning = $this->legality->positioning($crew, $trip, $seat);
            if ($positioning !== null) {
                $issues[] = ['code' => 'positioning', 'message' => $positioning];
            }
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
        // Duty minutes in the period per crew member, remembered until a move changes that person's schedule
        // (only the two people in a move change), so ordering candidates for every seat stays cheap.
        $used = [];
        $share = function (CrewMember $crew, int $extra = 0) use (&$used, $context, $from, $to, $weeks): float {
            $used[$crew->id] ??= $context->schedule($crew->id)->minutesBetween($from, $to);

            return ($used[$crew->id] + $extra) / max($this->legality->capacity($context, $crew) * $weeks, 1);
        };
        $moves = 0;
        for ($pass = 0; $pass < 3; $pass++) {
            $seats = [];
            foreach ($context->trips as $trip) {
                if ($context->operated($trip) || ($context->tripDuties[$trip->id] ?? null) === null) {
                    // Trips without a usable schedule have nothing to rebalance.
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
                $duty = $context->seatDuty($trip, $seat);
                $minutes = $duty->dutyMinutes();
                $holderShare = $share($holder);
                $holderAfter = $share($holder, -$minutes);
                $others = $context->crews->filter(fn (CrewMember $crew): bool => $crew->rank === $seat->rank && $crew->active && $crew->id !== $holder->id)
                    ->sortBy(fn (CrewMember $crew): array => [$share($crew), $crew->id]);
                foreach ($others as $crew) {
                    if ($share($crew) >= $holderShare || max($holderAfter, $share($crew, $minutes)) >= $holderShare - 0.0001) {
                        continue;
                    }
                    if ($this->legality->issues($context, $crew, $trip, $seat) !== [] || $this->legality->positioning($crew, $trip, $seat) !== null) {
                        continue;
                    }
                    $context->schedule($holder->id)->remove('assignment:'.$seat->id);
                    $context->schedule($crew->id)->add($duty->withKey('assignment:'.$seat->id));
                    unset($used[$holder->id], $used[$crew->id]);
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
