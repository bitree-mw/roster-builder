<?php

namespace Database\Factories;

use App\Models\Airport;
use App\Models\CrewMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CrewMember> */
class CrewMemberFactory extends Factory
{
    /**
     * Default attributes for an active crew member.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'rank' => 'CPT', 'base_airport' => Airport::factory(), 'all_aircraft' => false, 'active' => true];
    }
}
