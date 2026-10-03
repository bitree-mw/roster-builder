<?php

namespace App\Services;

use App\Models\Aircraft;
use App\Models\Assignment;
use App\Models\CrewMember;
use App\Models\RosterPeriod;
use App\Models\Trip;
use App\Support\Roster\Duty;
use App\Support\Roster\PlanningContext;
use Carbon\CarbonImmutable;

/**
 * Decides whether a crew member may hold a seat on a trip, and how fair the choice would be. The roster
 * generator, the conflict check and the manual seat editor all use these same checks, so a roster can never
 * be judged by different rules in different places.
 *
 * Checks use the week's frozen rules_snapshot. They are configurable planning defaults from the product
 * brief, not a certified statement of aviation regulations.
 */
class RosterLegalityService
{
    /** Seat names used in messages. */
    public const RANKS = ['CPT' => 'captain', 'FO' => 'first officer', 'CC' => 'cabin crew'];

    /** Problems a manual override can never accept (the seat would be meaningless or the data inconsistent). */
    public const HARD = ['inactive', 'rank', 'already_on_trip'];

    /** Plain-language names for rejection codes, used when explaining open seats. */
    public const REASON_LABELS = [
        'inactive' => 'inactive', 'rank' => 'other position', 'base' => 'based elsewhere', 'rating' => 'not rated on the aircraft',
        'document_missing' => 'document not on record', 'document_expired' => 'document expired', 'excluded' => 'excluded',
        'already_on_trip' => 'already on the trip', 'unavailable' => 'on leave, day off, SIM or standby', 'overlap' => 'already on duty',
        'rest' => 'not enough rest', 'duty_7d' => '7-day duty limit', 'block_month' => 'monthly block limit',
        'consecutive_days' => 'consecutive duty days', 'days_off_month' => 'monthly days off', 'weekly_hours' => 'weekly working hours',
    ];

    /** Every rostered crew member needs all three documents valid for the whole duty. */
    private const DOCUMENTS = ['licence' => 'Licence', 'medical' => 'Medical', 'recurrent' => 'Recurrent training'];

    /** Whole-day activities that make a crew member unavailable for flying. */
    private const ACTIVITIES = ['leave' => 'On leave', 'day_off' => 'Protected day off', 'sim' => 'Simulator session', 'standby' => 'Standby'];

    public function __construct(private ExpiryService $expiry) {}

    /**
     * Load everything needed to plan or check one week in a fixed number of queries. Neighbouring trips are
     * loaded from the whole calendar month(s) the week touches plus a week either side, so rest, seven-day,
     * consecutive-day and monthly limits see duties outside this week too.
     */
    public function context(RosterPeriod $period): PlanningContext
    {
        $rules = $period->rules_snapshot;
        $from = $period->starts_on->startOfMonth()->min($period->starts_on->subDays(7));
        // Far enough ahead for the longest rotation that starts in this roster.
        $to = $period->ends_on->endOfMonth()->max($period->ends_on->addDays(max(7, (int) config('roster.max_trip_days'))));
        $crews = CrewMember::query()
            ->with(['ratings', 'documents', 'activities' => fn ($query) => $query->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])])
            ->orderBy('id')->get()->keyBy('id');
        $trips = $period->trips()->with(['assignments' => fn ($query) => $query->orderBy('id'), 'exclusions', 'flight'])
            ->orderBy('start_date')->orderBy('id')->get()->keyBy('id');
        $context = new PlanningContext($period, $rules, $crews, $trips, $this->expiry->today()->format('Y-m-d'));

        foreach ($trips as $trip) {
            $context->tripDuties[$trip->id] = Duty::fromSnapshot('trip:'.$trip->id, $trip->id, $trip->schedule_snapshot);
        }
        // Activities: timed sim/standby sessions count as duty; anything untimed blocks the whole day.
        foreach ($crews as $crew) {
            $schedule = $context->schedule($crew->id);
            foreach ($crew->activities as $activity) {
                $date = $activity->date->format('Y-m-d');
                if ($activity->starts_at !== null && $activity->ends_at !== null && in_array($activity->type, ['sim', 'standby'], true)) {
                    $start = intdiv($activity->starts_at->getTimestamp(), 60);
                    $end = intdiv($activity->ends_at->getTimestamp(), 60);
                    $schedule->add(new Duty('activity:'.$activity->id, self::ACTIVITIES[$activity->type].' on '.$activity->date->format('d M'), null, [
                        ['start' => $start, 'end' => max($end, $start), 'block' => 0, 'date' => $date, 'dates' => [$date]],
                    ]));
                } else {
                    $schedule->unavailable[$date] = $activity->type;
                }
            }
        }
        // Seats held in other rosters (a rotation can start up to max_trip_days - 1 days before the range and
        // still overlap it, since its crew stay with it until it is back at base).
        $history = Assignment::query()->whereNotNull('crew_member_id')
            ->whereHas('trip', fn ($query) => $query->where('roster_period_id', '!=', $period->id)->whereBetween('start_date', [$from->subDays((int) config('roster.max_trip_days') - 1)->format('Y-m-d'), $to->format('Y-m-d')]))
            ->with('trip')->get();
        foreach ($history as $assignment) {
            $duty = Duty::fromSnapshot('assignment:'.$assignment->id, $assignment->trip_id, $assignment->trip->schedule_snapshot);
            if ($duty !== null) {
                $context->schedule($assignment->crew_member_id)->add($duty);
            }
        }
        // Seats already filled in this week.
        foreach ($trips as $trip) {
            foreach ($trip->assignments as $assignment) {
                if ($assignment->crew_member_id !== null && $context->tripDuties[$trip->id] !== null) {
                    $context->schedule($assignment->crew_member_id)->add($context->tripDuties[$trip->id]->withKey('assignment:'.$assignment->id));
                }
            }
        }
        $context->airframes = Aircraft::query()->toBase()->select('aircraft_type_id')->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'available' then 1 else 0 end) as available")->groupBy('aircraft_type_id')->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->aircraft_type_id => ['total' => (int) $row->total, 'available' => (int) $row->available]])->all();

        return $context;
    }

    /**
     * Every reason a crew member cannot legally hold a seat on a trip; an empty list means the assignment
     * is legal. $seat is the seat being filled (its current holder's own duty is ignored).
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function issues(PlanningContext $context, CrewMember $crew, Trip $trip, Assignment $seat): array
    {
        $issues = $this->eligibility($context, $crew, $trip, $seat->rank);
        if ($trip->assignments->contains(fn (Assignment $other): bool => $other->id !== $seat->id && $other->crew_member_id === $crew->id)) {
            $issues[] = ['code' => 'already_on_trip', 'message' => 'Already holds another seat on this trip.'];
        }
        $duty = $context->tripDuties[$trip->id] ?? null;
        if ($duty === null) {
            return $issues;
        }
        $schedule = $context->schedule($crew->id);
        // Every day away from base counts, including layover days at an outstation without flying.
        foreach ($duty->awayDates() as $date) {
            if (isset($schedule->unavailable[$date])) {
                $issues[] = ['code' => 'unavailable', 'message' => self::ACTIVITIES[$schedule->unavailable[$date]].' on '.CarbonImmutable::parse($date)->format('d M').'.'];
            }
        }

        return [...$issues, ...$this->limits($context->rules, $crew, $duty, $schedule->others('assignment:'.$seat->id, $trip->id))];
    }

    /**
     * Rule problems for a duty that is not a seat, such as a standby the generator wants to plan: days the
     * crew member is unavailable plus every duty limit against their other duties.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function dutyIssues(PlanningContext $context, CrewMember $crew, Duty $duty): array
    {
        $schedule = $context->schedule($crew->id);
        $issues = [];
        foreach ($duty->awayDates() as $date) {
            if (isset($schedule->unavailable[$date])) {
                $issues[] = ['code' => 'unavailable', 'message' => self::ACTIVITIES[$schedule->unavailable[$date]].' on '.CarbonImmutable::parse($date)->format('d M').'.'];
            }
        }

        return [...$issues, ...$this->limits($context->rules, $crew, $duty, $schedule->others($duty->key, null))];
    }

    /**
     * The crew member's weekly capacity in minutes: their working hours, or the rules' seven-day duty limit.
     */
    public function capacity(PlanningContext $context, CrewMember $crew): int
    {
        return (int) round($crew->weekly_hours ? $crew->weekly_hours * 60 : $context->rules['max_duty_7d_h'] * 60);
    }

    /**
     * Checks that do not depend on other duties: active, position, base, aircraft rating, valid documents and
     * manual exclusions. Also used to find the hardest seats (fewest eligible crew) first.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function eligibility(PlanningContext $context, CrewMember $crew, Trip $trip, string $rank): array
    {
        $snapshot = $trip->schedule_snapshot;
        $issues = [];
        if (! $crew->active) {
            $issues[] = ['code' => 'inactive', 'message' => 'Inactive crew member.'];
        }
        if ($crew->rank !== $rank) {
            $issues[] = ['code' => 'rank', 'message' => 'Holds '.$crew->rank.'; this is a '.self::RANKS[$rank].' seat.'];
        }
        if (isset($snapshot['base']) && $crew->base_airport !== $snapshot['base']) {
            $issues[] = ['code' => 'base', 'message' => 'Based at '.$crew->base_airport.'; this trip starts at '.$snapshot['base'].'.'];
        }
        $typeId = $snapshot['aircraft_type_id'] ?? null;
        if ($typeId !== null && ! ($crew->all_aircraft && $crew->rank === 'CC') && ! $crew->ratings->contains('id', $typeId)) {
            $issues[] = ['code' => 'rating', 'message' => 'Not rated on the '.($snapshot['aircraft_type'] ?? 'aircraft type').'.'];
        }
        // Documents must stay valid until the last day of the duty (a document is valid on its expiry date).
        $duty = $context->tripDuties[$trip->id] ?? null;
        $lastDate = $duty ? max($duty->dates()) : $trip->start_date->format('Y-m-d');
        foreach (self::DOCUMENTS as $kind => $label) {
            $document = $crew->documents->firstWhere('kind', $kind);
            // Missing records block only when the rules say so (policy "block"); with "warn" they are reported by
            // documentWarnings() instead. Rule snapshots from before the policy existed count as "warn".
            if ($document === null) {
                if (($context->rules['missing_documents'] ?? 'warn') === 'block') {
                    $issues[] = ['code' => 'document_missing', 'message' => 'No '.strtolower($label).' expiry on record.'];
                }
            } elseif ($document->expires_on->format('Y-m-d') < $lastDate) {
                $issues[] = ['code' => 'document_expired', 'message' => $label.' expires '.$document->expires_on->format('d M Y').', before this duty ends.'];
            }
        }
        if ($trip->exclusions->contains('crew_member_id', $crew->id)) {
            $issues[] = ['code' => 'excluded', 'message' => 'Excluded from this trip by crew control.'];
        }

        return $issues;
    }

    /**
     * Documents not on record for a crew member when the rules' missing-documents policy is "warn": the
     * seat may be held, but crew control is told which records to complete.
     *
     * @return array<int, string>
     */
    public function documentWarnings(PlanningContext $context, CrewMember $crew): array
    {
        if (($context->rules['missing_documents'] ?? 'warn') === 'block') {
            return [];
        }

        return collect(self::DOCUMENTS)->reject(fn (string $label, string $kind): bool => $crew->documents->contains('kind', $kind))
            ->map(fn (string $label): string => 'No '.strtolower($label).' expiry on record.')->values()->all();
    }

    /**
     * How fair it would be to give this duty to the crew member; the generator picks the lowest score.
     * Score = share of weekly working hours used after the duty (%) + block hours already flown this month
     * + 1.5 × trips already flown this month. Without personal working hours, the rules' seven-day duty
     * limit is the weekly capacity.
     *
     * @return array{score: float, week_minutes: int, capacity_minutes: int, month_block_minutes: int, month_trips: int}
     */
    public function score(PlanningContext $context, CrewMember $crew, Trip $trip, Assignment $seat): array
    {
        $duty = $context->tripDuties[$trip->id];
        $schedule = $context->schedule($crew->id);
        $monday = Duty::weekOf($duty->firstDate());
        $month = substr($duty->firstDate(), 0, 7);
        $weekMinutes = $schedule->weekMinutes($monday, 'assignment:'.$seat->id);
        $capacity = $this->capacity($context, $crew);
        $monthBlock = 0;
        $monthTrips = 0;
        foreach ($schedule->others('assignment:'.$seat->id, $trip->id) as $other) {
            if ($other->tripId !== null && substr($other->firstDate(), 0, 7) === $month) {
                $monthBlock += $other->blockMinutes();
                $monthTrips++;
            }
        }
        $score = 100 * ($weekMinutes + $duty->dutyMinutes()) / max($capacity, 1) + $monthBlock / 60 + 1.5 * $monthTrips;

        return ['score' => round($score, 2), 'week_minutes' => $weekMinutes, 'capacity_minutes' => $capacity, 'month_block_minutes' => $monthBlock, 'month_trips' => $monthTrips];
    }

    /**
     * Duty-time limits against the crew member's other duties: overlap, minimum rest, rolling seven-day duty,
     * calendar-month block, consecutive duty days, minimum monthly days off (leave counts as off) and the
     * crew member's own weekly working hours.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<int, Duty>  $others
     * @return array<int, array{code: string, message: string}>
     */
    private function limits(array $rules, CrewMember $crew, Duty $duty, array $others): array
    {
        $issues = [];
        $minRest = (int) round($rules['min_rest_h'] * 60);
        foreach ($others as $other) {
            if ($other->start() < $duty->end() && $duty->start() < $other->end()) {
                $issues[] = ['code' => 'overlap', 'message' => 'Overlaps '.$other->label.'.'];
            } elseif ($other->end() <= $duty->start() && $duty->start() - $other->end() < $minRest) {
                $issues[] = ['code' => 'rest', 'message' => 'Only '.Duty::hours($duty->start() - $other->end()).' rest after '.$other->label.' (minimum '.Duty::hours($minRest).').'];
            } elseif ($other->start() >= $duty->end() && $other->start() - $duty->end() < $minRest) {
                $issues[] = ['code' => 'rest', 'message' => 'Only '.Duty::hours($other->start() - $duty->end()).' rest before '.$other->label.' (minimum '.Duty::hours($minRest).').'];
            }
        }
        $periods = array_merge(...array_map(fn (Duty $item): array => $item->periods, [...$others, $duty]));

        // Rolling seven days: every window that starts at a report time and contains one of this duty's periods.
        $week = 7 * 1440;
        $limit = (int) round($rules['max_duty_7d_h'] * 60);
        $worst = ['minutes' => 0, 'start' => null];
        foreach ($duty->periods as $own) {
            foreach ($periods as $candidate) {
                $start = $candidate['start'];
                if ($start > $own['start'] || $start <= $own['start'] - $week) {
                    continue;
                }
                $minutes = array_sum(array_map(fn (array $period): int => $period['start'] >= $start && $period['start'] < $start + $week ? $period['end'] - $period['start'] : 0, $periods));
                if ($minutes > $worst['minutes']) {
                    $worst = ['minutes' => $minutes, 'start' => $candidate['date']];
                }
            }
        }
        if ($worst['minutes'] > $limit) {
            $issues[] = ['code' => 'duty_7d', 'message' => 'Duty in the 7 days from '.CarbonImmutable::parse($worst['start'])->format('d M').' would be '.Duty::hours($worst['minutes']).' (limit '.Duty::hours($limit).').'];
        }

        // Calendar-month block time, by base-local report date.
        $blockLimit = (int) round($rules['max_block_month_h'] * 60);
        foreach (array_unique(array_map(fn (array $period): string => substr($period['date'], 0, 7), $duty->periods)) as $month) {
            $block = array_sum(array_map(fn (array $period): int => str_starts_with($period['date'], $month) ? $period['block'] : 0, $periods));
            if ($block > $blockLimit) {
                $issues[] = ['code' => 'block_month', 'message' => 'Block time in '.CarbonImmutable::parse($month.'-01')->format('F').' would be '.Duty::hours($block).' (limit '.Duty::hours($blockLimit).').'];
            }
        }

        // Consecutive duty days: the run of duty dates that includes this duty.
        $dutyDates = array_flip(array_merge(...array_column($periods, 'dates')));
        $run = 0;
        foreach ($duty->dates() as $date) {
            $length = 1;
            for ($day = CarbonImmutable::parse($date)->subDay(); isset($dutyDates[$day->format('Y-m-d')]); $day = $day->subDay()) {
                $length++;
            }
            for ($day = CarbonImmutable::parse($date)->addDay(); isset($dutyDates[$day->format('Y-m-d')]); $day = $day->addDay()) {
                $length++;
            }
            $run = max($run, $length);
        }
        if ($run > $rules['max_consecutive_days']) {
            $issues[] = ['code' => 'consecutive_days', 'message' => 'Would make '.$run.' consecutive duty days (limit '.$rules['max_consecutive_days'].').'];
        }

        // Minimum days off in each calendar month this duty touches (days without any duty count as off).
        foreach (array_unique(array_map(fn (string $date): string => substr($date, 0, 7), $duty->dates())) as $month) {
            $first = CarbonImmutable::parse($month.'-01');
            $daysOff = $first->daysInMonth - count(array_filter(array_keys($dutyDates), fn (string $date): bool => str_starts_with($date, $month)));
            if ($daysOff < $rules['min_days_off_month']) {
                $issues[] = ['code' => 'days_off_month', 'message' => 'Would leave only '.$daysOff.' days off in '.$first->format('F').' (minimum '.$rules['min_days_off_month'].').'];
            }
        }

        // The crew member's own contracted working hours for each Monday–Sunday week the duty falls in.
        if ($crew->weekly_hours) {
            $capacity = $crew->weekly_hours * 60;
            foreach (array_unique(array_map(fn (array $period): string => Duty::weekOf($period['date']), $duty->periods)) as $monday) {
                $minutes = array_sum(array_map(fn (array $period): int => Duty::weekOf($period['date']) === $monday ? $period['end'] - $period['start'] : 0, $periods));
                if ($minutes > $capacity) {
                    $issues[] = ['code' => 'weekly_hours', 'message' => 'Week duty would be '.Duty::hours($minutes).' against '.$crew->weekly_hours.'h working hours.'];
                }
            }
        }

        return $issues;
    }
}
