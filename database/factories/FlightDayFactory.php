<?php

namespace Database\Factories;

use App\Models\Flight;
use App\Models\FlightDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FlightDay> */
class FlightDayFactory extends Factory
{
    public function definition(): array
    {
        return ['flight_id' => Flight::factory(), 'weekday' => 0];
    }
}
