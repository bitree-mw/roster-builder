<?php

namespace Database\Factories;

use App\Models\Flight;
use App\Models\RosterPeriod;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Trip> */
class TripFactory extends Factory
{
    public function definition(): array
    {
        return ['roster_period_id' => RosterPeriod::factory(), 'flight_id' => Flight::factory(), 'start_date' => '2026-10-01', 'schedule_snapshot' => ['code' => 'LB1', 'periods' => []]];
    }
}
