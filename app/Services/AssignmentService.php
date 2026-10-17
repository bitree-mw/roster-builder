<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CrewActivity;
use App\Models\CrewMember;
use App\Models\Exclusion;
use App\Models\RosterPeriod;
use App\Models\Trip;
use App\Models\User;
use App\Support\Roster\PlanningContext;
use App\Support\Roster\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual roster edits by a scheduler. Assigning someone locks the seat ("manual"), so rebuilding the week
 * keeps it. Clearing a seat hands it back to the generator. An assignment that breaks a rule is only saved
 * with a written reason; the accepted problems are stored as flag_reasons so the conflict check treats them
 * as acknowledged, and any new problem that appears later is still reported.
 *
 * Captain seats can be split into sections at any stop (split/join), so captains may fly only part of a
 * trip; first officers and cabin crew always fly it all. A captain joining or leaving a section away from
 * their base is allowed and reported as a positioning warning.
 *
 * Assigning a flight to someone on standby that day clears the standby in the same transaction. Every
 * manual change keeps the seat's previous state so it can be undone once; a crew member can also be
 * excluded from a trip so the generator never puts them back on it.
 */
class AssignmentService
{
    public function __construct(private AuditService $audit, private RosterLegalityService $legality, private RosterPeriodService $periods) {}

    /**
     * Put a crew member in a seat (or clear it with null).
     *
     * @throws ValidationException when the week cannot change, the trip has operated, the problem cannot be
     *                             overridden, or rules are broken without an override reason
     */
    public function assign(Assignment $assignment, ?int $crewId, ?string $reason, User $actor): Assignment
    {
        return DB::transaction(function () use ($assignment, $crewId, $reason, $actor): Assignment {
            [$context, $trip, $seat] = $this->open($assignment);
            $before = $seat->toArray();
            $log = $this->log($actor, $seat);

            if ($crewId === null) {
                $seat->forceFill(['crew_member_id' => null, 'source' => 'auto', 'flag_reasons' => null, 'decision_log' => [...$log, 'action' => 'cleared']])->save();
                $this->audit->record($actor, 'cleared', $seat, $before, $seat->toArray());

                return $seat->load('crewMember');
            }

            $crew = $context->crews->get($crewId);
            $standby = $this->releaseStandby($context, $crew, $trip, $seat);
            $issues = $this->legality->issues($context, $crew, $trip, $seat);
            $hard = array_values(array_filter($issues, fn (array $issue): bool => in_array($issue['code'], RosterLegalityService::HARD, true)));
            if ($hard !== []) {
                throw ValidationException::withMessages(['crew_member_id' => array_column($hard, 'message')]);
            }
            $reason = trim((string) $reason);
            if ($issues !== [] && $reason === '') {
                throw ValidationException::withMessages([
                    'crew_member_id' => array_column($issues, 'message'),
                    'override_reason' => 'Record a reason to assign '.$crew->name.' despite these rule checks.',
                ]);
            }
            $messages = array_column($issues, 'message');
            // The flight replaces any standby planned on its days.
            foreach ($standby as $activity) {
                $this->audit->record($actor, 'deleted', $activity, $activity->toArray(), ['reason' => 'Cleared by flight assignment']);
                $activity->delete();
            }
            $seat->forceFill([
                'crew_member_id' => $crew->id,
                'source' => 'manual',
                'flag_reasons' => $messages === [] ? null : $messages,
                'decision_log' => [...$log, 'action' => $messages === [] ? 'assigned' : 'overridden', 'reason' => $reason === '' ? null : $reason, 'issues' => $messages,
                    // Accepted without a reason: crew control arranges the captain's travel to or from the section.
                    'positioning' => $this->legality->positioning($crew, $trip, $seat),
                    'cleared_standby' => array_map(fn (CrewActivity $activity): string => $activity->date->format('Y-m-d'), $standby)],
            ])->save();
            $this->audit->record($actor, $messages === [] ? 'assigned' : 'overridden', $seat, $before, $seat->toArray());

            return $seat->load('crewMember');
        });
    }

    /**
     * Split a captain seat after one of its legs, so two captains can share the trip: the seat keeps its holder
     * for the legs up to and including $afterLeg, and a new open captain seat covers the rest (the generator or
     * crew control fills it). Captains may change at any stop, base or outstation; first officers and cabin crew
     * always fly the whole trip, so only captain seats can be split.
     *
     * @return array{0: Assignment, 1: Assignment} the shortened seat and the new section seat
     *
     * @throws ValidationException when the seat is not a captain seat, the leg is not inside it, or the week or
     *                             trip cannot change
     */
    public function split(Assignment $assignment, int $afterLeg, User $actor): array
    {
        return DB::transaction(function () use ($assignment, $afterLeg, $actor): array {
            [, $trip, $seat] = $this->open($assignment);
            if ($seat->rank !== 'CPT') {
                throw ValidationException::withMessages(['after_leg' => 'Only captain seats can be split. First officers and cabin crew fly the whole trip.']);
            }
            $snapshot = $trip->schedule_snapshot;
            [$from, $to] = [$seat->from_leg ?? 1, $seat->to_leg ?? Section::count($snapshot)];
            if ($afterLeg < $from || $afterLeg >= $to) {
                throw ValidationException::withMessages(['after_leg' => 'Choose a stop inside this captain\'s section, before its last leg.']);
            }
            $before = $seat->toArray();
            // Later sections move up one seat number, highest first so the unique seat index is never broken.
            foreach ($trip->assignments->where('rank', 'CPT')->where('seat_number', '>', $seat->seat_number)->sortByDesc('seat_number') as $later) {
                $later->forceFill(['seat_number' => $later->seat_number + 1])->save();
            }
            $stop = Section::ends($snapshot, $from, $afterLeg)[1];
            $seat->forceFill(['from_leg' => $from, 'to_leg' => $afterLeg, 'decision_log' => [...($seat->decision_log ?? []), 'split' => ['at' => $stop, 'by' => $actor->name, 'on' => now()->toIso8601String()]]])->save();
            $section = $trip->assignments()->create(['rank' => 'CPT', 'seat_number' => $seat->seat_number + 1, 'from_leg' => $afterLeg + 1, 'to_leg' => $to, 'source' => 'auto',
                'decision_log' => ['by' => 'manual', 'user' => $actor->name, 'at' => now()->toIso8601String(), 'action' => 'split', 'reason' => 'Captain change at '.$stop.'.']]);
            $this->audit->record($actor, 'split', $seat, $before, ['seat' => $seat->toArray(), 'new_seat' => $section->toArray()]);

            // The trip is already loaded: attach it so callers can describe the sections without another query.
            return [$seat->load('crewMember')->setRelation('trip', $trip), $section->setRelation('trip', $trip)];
        });
    }

    /**
     * Join a captain section with the next one, so one captain flies both again. The joined seat is open (its
     * longer duty has to be checked afresh), and becomes the whole-trip seat when it covers every leg. Both
     * captains who held the two sections are released.
     *
     * @return array{seat: Assignment, released: array<int, string>} the joined seat and the names released
     *
     * @throws ValidationException when there is no next section or the week or trip cannot change
     */
    public function join(Assignment $assignment, User $actor): array
    {
        return DB::transaction(function () use ($assignment, $actor): array {
            [$context, $trip, $seat] = $this->open($assignment);
            $next = $trip->assignments->where('rank', 'CPT')->firstWhere('seat_number', $seat->seat_number + 1);
            if ($seat->rank !== 'CPT' || $next === null) {
                throw ValidationException::withMessages(['assignment' => 'There is no following captain section to join.']);
            }
            $released = array_values(array_filter([$context->crews->get((int) $seat->crew_member_id)?->name, $context->crews->get((int) $next->crew_member_id)?->name]));
            $before = ['seat' => $seat->toArray(), 'next' => $next->toArray()];
            $legs = Section::count($trip->schedule_snapshot);
            $whole = ($seat->from_leg ?? 1) === 1 && ($next->to_leg ?? $legs) === $legs;
            $next->delete();
            $seat->forceFill(['from_leg' => $whole ? null : $seat->from_leg, 'to_leg' => $whole ? null : $next->to_leg, 'crew_member_id' => null, 'source' => 'auto', 'flag_reasons' => null,
                'decision_log' => ['by' => 'manual', 'user' => $actor->name, 'at' => now()->toIso8601String(), 'action' => 'joined', 'released' => $released]])->save();
            // Later sections move down one seat number, lowest first so the unique seat index is never broken.
            foreach ($trip->assignments->where('rank', 'CPT')->where('seat_number', '>', $next->seat_number)->sortBy('seat_number') as $later) {
                $later->forceFill(['seat_number' => $later->seat_number - 1])->save();
            }
            $this->audit->record($actor, 'joined', $seat, $before, $seat->toArray());

            return ['seat' => $seat->setRelation('trip', $trip), 'released' => $released];
        });
    }

    /**
     * Never assign the seat's current holder to this trip again (manually or by the generator), and open the
     * seat so another crew member can take it.
     *
     * @throws ValidationException when the seat is empty or cannot change
     */
    public function exclude(Assignment $assignment, User $actor): Assignment
    {
        return DB::transaction(function () use ($assignment, $actor): Assignment {
            [, $trip, $seat] = $this->open($assignment);
            if ($seat->crew_member_id === null) {
                throw ValidationException::withMessages(['assignment' => 'This seat is open; there is nobody to exclude.']);
            }
            $before = $seat->toArray();
            $exclusion = Exclusion::query()->firstOrCreate(['trip_id' => $trip->id, 'crew_member_id' => $seat->crew_member_id]);
            $this->audit->record($actor, 'created', $exclusion, null, $exclusion->toArray());
            $seat->forceFill(['crew_member_id' => null, 'source' => 'auto', 'flag_reasons' => null, 'decision_log' => [...$this->log($actor, $seat), 'action' => 'excluded', 'excluded_crew_member_id' => $exclusion->crew_member_id]])->save();
            $this->audit->record($actor, 'cleared', $seat, $before, $seat->toArray());

            return $seat;
        });
    }

    /**
     * Allow an excluded crew member on the trip again.
     *
     * @throws ValidationException when the week cannot change or the trip has operated
     */
    public function removeExclusion(Exclusion $exclusion, User $actor): void
    {
        DB::transaction(function () use ($exclusion, $actor): void {
            $trip = Trip::query()->findOrFail($exclusion->trip_id);
            $period = RosterPeriod::query()->lockForUpdate()->findOrFail($trip->roster_period_id);
            $this->periods->assertEditable($period);
            $this->audit->record($actor, 'deleted', $exclusion, $exclusion->toArray());
            $exclusion->delete();
        });
    }

    /**
     * Restore the seat as it was before its last manual change (one step). The restored holder must not
     * already hold another seat on the trip; any rule problems simply show again as conflicts.
     *
     * @throws ValidationException when there is nothing to undo or the restore is impossible
     */
    public function undo(Assignment $assignment, User $actor): Assignment
    {
        return DB::transaction(function () use ($assignment, $actor): Assignment {
            [, $trip, $seat] = $this->open($assignment);
            $previous = $seat->decision_log['previous'] ?? null;
            if ($previous === null) {
                throw ValidationException::withMessages(['assignment' => 'There is no earlier change to undo for this seat.']);
            }
            if ($previous['crew_member_id'] !== null && $trip->assignments->contains(fn (Assignment $other): bool => $other->id !== $seat->id && $other->crew_member_id === $previous['crew_member_id'])) {
                throw ValidationException::withMessages(['assignment' => 'The previous crew member now holds another seat on this trip.']);
            }
            $before = $seat->toArray();
            // Undoing an exclusion also lifts it, otherwise the restored crew member would be flagged at once.
            if (($seat->decision_log['action'] ?? null) === 'excluded') {
                Exclusion::query()->where('trip_id', $trip->id)->where('crew_member_id', $seat->decision_log['excluded_crew_member_id'] ?? 0)->delete();
            }
            $seat->forceFill(['crew_member_id' => $previous['crew_member_id'], 'source' => $previous['source'], 'flag_reasons' => $previous['flag_reasons'], 'decision_log' => $previous['decision_log']])->save();
            $this->audit->record($actor, 'undone', $seat, $before, $seat->toArray());

            return $seat->load('crewMember');
        });
    }

    /**
     * Everyone who could take the seat, legal choices first (lowest fairness score first), then the rest with
     * the reasons they would break. Only active crew of the seat's position are offered. Standby on the
     * trip's days is not counted against a candidate because assigning them clears it.
     *
     * @return array<int, array{crew_member_id: int, name: string, rank: string, base_airport: string, weekly_hours: ?int, current: bool, legal: bool, overridable: bool, clears_standby: bool, issues: array<int, array{code: string, message: string}>, warnings: array<int, array{code: string, message: string}>, score: ?float, week_minutes: int, capacity_minutes: int}>
     */
    public function candidates(Assignment $assignment): array
    {
        $period = RosterPeriod::query()->findOrFail($assignment->trip()->value('roster_period_id'));
        $context = $this->legality->context($period);
        $trip = $context->trips->get($assignment->trip_id);
        $seat = $trip->assignments->firstWhere('id', $assignment->id);
        $duty = $context->seatDuty($trip, $seat);
        $rows = [];
        foreach ($context->crews as $crew) {
            if ($crew->rank !== $seat->rank || ! $crew->active) {
                continue;
            }
            $standby = $this->releaseStandby($context, $crew, $trip, $seat);
            $issues = $this->legality->issues($context, $crew, $trip, $seat);
            $score = $duty ? $this->legality->score($context, $crew, $trip, $seat) : null;
            // Joining or leaving a captain section away from base: allowed, shown as a warning.
            $positioning = $this->legality->positioning($crew, $trip, $seat);
            $rows[] = [
                'crew_member_id' => $crew->id,
                'name' => $crew->name,
                'rank' => $crew->rank,
                'base_airport' => $crew->base_airport,
                'weekly_hours' => $crew->weekly_hours,
                'current' => $seat->crew_member_id === $crew->id,
                'legal' => $issues === [],
                'overridable' => ! collect($issues)->contains(fn (array $issue): bool => in_array($issue['code'], RosterLegalityService::HARD, true)),
                'clears_standby' => $standby !== [],
                'issues' => $issues,
                'warnings' => $positioning === null ? [] : [['code' => 'positioning', 'message' => $positioning]],
                'score' => $score['score'] ?? null,
                'week_minutes' => $score['week_minutes'] ?? 0,
                'capacity_minutes' => $score['capacity_minutes'] ?? 0,
            ];
        }
        // Legal first; among them, captains who need no positioning first.
        usort($rows, fn (array $a, array $b): int => [! $a['legal'], count($a['issues']), count($a['warnings']), $a['score'] ?? PHP_FLOAT_MAX, $a['name'], $a['crew_member_id']]
            <=> [! $b['legal'], count($b['issues']), count($b['warnings']), $b['score'] ?? PHP_FLOAT_MAX, $b['name'], $b['crew_member_id']]);

        return $rows;
    }

    /**
     * Lock the seat's week, check it can change, and load the planning context.
     *
     * @return array{0: PlanningContext, 1: Trip, 2: Assignment}
     *
     * @throws ValidationException when the week is published/ended or the trip has operated
     */
    private function open(Assignment $assignment): array
    {
        // Lock the week first so edits and builds of the same week run one at a time.
        $period = RosterPeriod::query()->lockForUpdate()->findOrFail($assignment->trip()->value('roster_period_id'));
        $this->periods->assertEditable($period);
        $context = $this->legality->context($period);
        $trip = $context->trips->get($assignment->trip_id);
        if ($context->operated($trip)) {
            throw ValidationException::withMessages(['crew_member_id' => 'This trip has already operated and can no longer be changed.']);
        }

        return [$context, $trip, $trip->assignments->firstWhere('id', $assignment->id)];
    }

    /**
     * The decision log for a manual change, carrying the seat's current state so the change can be undone.
     *
     * @return array<string, mixed>
     */
    private function log(User $actor, Assignment $seat): array
    {
        $previousLog = $seat->decision_log;
        unset($previousLog['previous']);

        return ['by' => 'manual', 'user_id' => $actor->id, 'user' => $actor->name, 'at' => now()->toIso8601String(),
            'previous' => ['crew_member_id' => $seat->crew_member_id, 'source' => $seat->source, 'flag_reasons' => $seat->flag_reasons, 'decision_log' => $previousLog]];
    }

    /**
     * Take the crew member's standby on the trip's days out of their schedule (in memory only) so it is not
     * counted against the flight that replaces it, and return those standby items.
     *
     * @return array<int, CrewActivity>
     */
    private function releaseStandby(PlanningContext $context, CrewMember $crew, Trip $trip, Assignment $seat): array
    {
        // A captain section only clears standby on the days of that section.
        $duty = $context->seatDuty($trip, $seat);
        if ($duty === null) {
            return [];
        }
        $schedule = $context->schedule($crew->id);
        $released = [];
        foreach ($crew->activities as $activity) {
            if ($activity->type === 'standby' && in_array($activity->date->format('Y-m-d'), $duty->awayDates(), true)) {
                $schedule->remove('activity:'.$activity->id);
                if (($schedule->unavailable[$activity->date->format('Y-m-d')] ?? null) === 'standby') {
                    unset($schedule->unavailable[$activity->date->format('Y-m-d')]);
                }
                $released[] = $activity;
            }
        }

        return $released;
    }
}
