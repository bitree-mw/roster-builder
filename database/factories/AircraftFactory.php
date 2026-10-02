<?php

namespace Database\Factories;

use App\Models\Aircraft;
use App\Models\AircraftType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Aircraft> */
class AircraftFactory extends Factory
{
    /**
     * Default attributes for an available airframe with a random 7Q- registration.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['registration' => strtoupper(fake()->unique()->bothify('7Q-???')), 'aircraft_type_id' => AircraftType::factory(), 'status' => 'available', 'airframe_hours' => 12000];
    }

    /**
     * An airframe grounded (AOG) with a reason, as AircraftService::changeStatus would leave it.
     */
    public function grounded(): static
    {
        return $this->state(['status' => 'grounded', 'status_reason' => 'Hydraulic leak', 'status_changed_at' => now()]);
    }
}
