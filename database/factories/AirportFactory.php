<?php

namespace Database\Factories;

use App\Models\Airport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Airport> */
class AirportFactory extends Factory
{
    /**
     * Default attributes for an airport.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['code' => strtoupper(fake()->unique()->lexify('???')), 'name' => fake()->city(), 'utc_offset_minutes' => 120, 'is_base' => true];
    }
}
