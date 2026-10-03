<?php

namespace App\Support\Roster;

use App\Models\CrewMember;
use App\Models\RosterPeriod;
use App\Models\Trip;
use Illuminate\Support\Collection;

/**
 * Everything the roster generator, conflict check and seat editor need for one week, loaded once by
 * RosterLegalityService::context(): the frozen duty rules, every crew member with ratings, documents and
 * schedule, the week's trips with their seats, and fleet availability per aircraft type.
 */
final class PlanningContext
{
    /** @var array<int, CrewSchedule> keyed by crew member id */
    public array $schedules = [];

    /** @var array<int, Duty|null> candidate duty for each trip in the week, keyed by trip id */
    public array $tripDuties = [];

    /** @var array<int, array{total: int, available: int}> registered and available airframes per aircraft type id */
    public array $airframes = [];

    /** @var array<int, array{base: string|null, aircraft_type_id: int|null, aircraft_type: string|null, last_date: string}> per-trip facts, see tripFacts() */
    private array $tripFacts = [];

    /** @var array<int, array<string, mixed>> per-crew facts, see crewFacts() */
    private array $crewFacts = [];

    /**
     * @param  array<string, mixed>  $rules  the period's rules_snapshot
     * @param  Collection<int, CrewMember>  $crews  keyed by id
     * @param  Collection<int, Trip>  $trips  keyed by id, with assignments, exclusions and flight loaded
     * @param  string  $today  base-local date (Y-m-d); trips before it have already operated
     */
    public function __construct(public RosterPeriod $period, public array $rules, public Collection $crews, public Collection $trips, public string $today) {}

    /** The schedule for a crew member, created empty on first use. */
    public function schedule(int $crewId): CrewSchedule
    {
        return $this->schedules[$crewId] ??= new CrewSchedule;
    }

    /**
     * What the eligibility check needs from a trip, read once per build. The schedule snapshot is a JSON column
     * that Eloquent decodes on every read, and the check runs for every seat × candidate pair, so reading it
     * once keeps long (two-week and monthly) builds fast. Snapshots do not change during a build.
     *
     * @return array{base: string|null, aircraft_type_id: int|null, aircraft_type: string|null, last_date: string}
     */
    public function tripFacts(Trip $trip): array
    {
        if (! isset($this->tripFacts[$trip->id])) {
            $snapshot = $trip->schedule_snapshot;
            $duty = $this->tripDuties[$trip->id] ?? null;
            $this->tripFacts[$trip->id] = [
                'base' => $snapshot['base'] ?? null,
                'aircraft_type_id' => $snapshot['aircraft_type_id'] ?? null,
                'aircraft_type' => $snapshot['aircraft_type'] ?? null,
                // Documents must stay valid until the last day of the duty.
                'last_date' => $duty ? max($duty->dates()) : $trip->start_date->format('Y-m-d'),
            ];
        }

        return $this->tripFacts[$trip->id];
    }

    /**
     * A crew member's fields used by the legality checks (active, rank, base, all-aircraft, weekly hours), their
     * document expiry dates (first record of each kind, as Y-m-d and "d M Y") and rated aircraft type ids, read
     * once per build: model attribute reads and date casts are slow when repeated for every seat checked, and
     * none of these change during a build.
     *
     * @return array{active: bool, rank: string, base: string, all_aircraft: bool, weekly_hours: int|null, documents: array<string, array{0: string, 1: string}>, ratings: array<int, true>}
     */
    public function crewFacts(CrewMember $crew): array
    {
        if (! isset($this->crewFacts[$crew->id])) {
            $documents = [];
            foreach ($crew->documents as $document) {
                $documents[$document->kind] ??= [$document->expires_on->format('Y-m-d'), $document->expires_on->format('d M Y')];
            }
            $this->crewFacts[$crew->id] = [
                'active' => (bool) $crew->active, 'rank' => $crew->rank, 'base' => $crew->base_airport, 'all_aircraft' => (bool) $crew->all_aircraft,
                'weekly_hours' => $crew->weekly_hours ? (int) $crew->weekly_hours : null,
                'documents' => $documents, 'ratings' => array_fill_keys($crew->ratings->pluck('id')->all(), true),
            ];
        }

        return $this->crewFacts[$crew->id];
    }

    /** Whether a trip starts before today and so can no longer be changed. */
    public function operated(Trip $trip): bool
    {
        return $trip->start_date->format('Y-m-d') < $this->today;
    }
}
