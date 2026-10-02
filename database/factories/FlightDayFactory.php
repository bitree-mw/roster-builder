<?php

namespace Database\Factories;

use App\Models\Flight;
use App\Models\FlightDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FlightDay> */
class FlightDayFactory extends Factory
{
    /**
     * Default attributes for a flight operating weekday.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['flight_id' => Flight::factory(), 'weekday' => 0];
    }
}
