<?php

namespace Database\Factories;

use App\Models\AircraftType;
use App\Models\Flight;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Flight> */
class FlightFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => strtoupper(fake()->unique()->bothify('LB###??')), 'aircraft_type_id' => AircraftType::factory(), 'active' => true];
    }
}
