<?php

namespace Database\Factories;

use App\Models\AircraftType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AircraftType> */
class AircraftTypeFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => strtoupper(fake()->unique()->bothify('A##??')), 'cabin_crew_required' => 2, 'palette' => 'forest'];
    }
}
