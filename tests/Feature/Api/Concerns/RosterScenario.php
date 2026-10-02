<?php

namespace Tests\Feature\Api\Concerns;

use App\Models\AircraftType;
use App\Models\Airport;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\RosterPeriod;
use App\Models\RuleSet;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * A small, fixed planning world for weekly roster tests: standard rules, LLW and BLZ bases, a Q400 needing
 * one cabin crew member, and helpers for flight patterns, crew and roster weeks. Tests travel to Thursday
 * 1 October 2026, so the week of Monday 5 October is the next (upcoming) week.
 */
trait RosterScenario
{
    protected AircraftType $q400;

    /** Standard rules, two bases and the Q400 type, at a fixed time. */
    protected function scenario(): void
    {
        $this->travelTo('2026-10-01 08:00:00');
        RuleSet::factory()->create(['id' => 1]);
        Airport::factory()->create(['code' => 'LLW', 'is_base' => true]);
        Airport::factory()->create(['code' => 'BLZ', 'is_base' => true]);
        $this->q400 = AircraftType::factory()->create(['code' => 'Q400', 'cabin_crew_required' => 1]);
    }

    /**
     * An LLW–BLZ–LLW shuttle on the given weekdays (Monday = 0), out at $departs and back 1h40 later.
     * With the default 08:00 departure the duty runs 07:00–11:10 local (4h10).
     *
     * @param  array<int, int>  $weekdays
     */
    protected function flight(string $code, array $weekdays, string $departs = '08:00', bool $active = true): Flight
    {
        $flight = Flight::factory()->create(['code' => $code, 'aircraft_type_id' => $this->q400->id, 'active' => $active]);
        $flight->days()->createMany(array_map(fn (int $day): array => ['weekday' => $day], $weekdays));
        $at = fn (int $minutes): string => date('H:i', strtotime($departs) + $minutes * 60);
        $flight->legs()->createMany([
            ['trip_day' => 1, 'sequence' => 1, 'from_airport' => 'LLW', 'to_airport' => 'BLZ', 'departs_local' => $departs, 'arrives_local' => $at(60)],
            ['trip_day' => 1, 'sequence' => 2, 'from_airport' => 'BLZ', 'to_airport' => 'LLW', 'departs_local' => $at(100), 'arrives_local' => $at(160)],
        ]);

        return $flight;
    }

    /**
     * An active LLW crew member, rated on the Q400 (cabin crew: all aircraft), with all documents valid.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function crew(string $rank, string $name, array $attributes = []): CrewMember
    {
        $crew = CrewMember::factory()->create(['name' => $name, 'rank' => $rank, 'base_airport' => 'LLW', 'all_aircraft' => $rank === 'CC', 'weekly_hours' => null, ...$attributes]);
        if ($rank !== 'CC') {
            $crew->ratings()->attach($this->q400->id);
        }
        foreach (['licence', 'medical', 'recurrent'] as $kind) {
            $crew->documents()->create(['kind' => $kind, 'expires_on' => '2027-12-31']);
        }

        return $crew;
    }

    /** A roster week with the current rules snapshot. */
    protected function week(string $monday = '2026-10-05', string $status = 'draft'): RosterPeriod
    {
        return RosterPeriod::factory()->create([
            'starts_on' => $monday,
            'ends_on' => date('Y-m-d', strtotime($monday.' +6 days')),
            'status' => $status,
            'rules_snapshot' => RuleSet::findOrFail(1)->makeHidden(['id', 'created_at', 'updated_at'])->toArray(),
        ]);
    }

    /** Act as a scheduler with a browser-equivalent token. */
    protected function actingAsScheduler(): User
    {
        $user = User::factory()->create(['role' => 'scheduler']);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
