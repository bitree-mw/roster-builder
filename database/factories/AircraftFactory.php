<?php

namespace Database\Factories;

use App\Models\Aircraft;
use App\Models\AircraftType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Aircraft> */
class AircraftFactory extends Factory
{
    public function definition(): array
    {
        return ['registration' => strtoupper(fake()->unique()->bothify('7Q-???')), 'aircraft_type_id' => AircraftType::factory(), 'status' => 'available', 'airframe_hours' => 12000];
    }

    public function grounded(): static
    {
        return $this->state(['status' => 'grounded', 'status_reason' => 'Hydraulic leak', 'status_changed_at' => now()]);
    }
}
