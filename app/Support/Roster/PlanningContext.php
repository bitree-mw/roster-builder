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

    /** Whether a trip starts before today and so can no longer be changed. */
    public function operated(Trip $trip): bool
    {
        return $trip->start_date->format('Y-m-d') < $this->today;
    }
}
