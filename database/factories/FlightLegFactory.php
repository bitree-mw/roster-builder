<?php

namespace Database\Factories;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightLeg;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FlightLeg> */
class FlightLegFactory extends Factory
{
    /**
     * Default attributes for a flight leg.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['flight_id' => Flight::factory(), 'trip_day' => 1, 'sequence' => 1, 'from_airport' => Airport::factory(), 'to_airport' => Airport::factory(), 'departs_local' => '08:00', 'arrives_local' => '09:00'];
    }
}
