<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\CrewMember;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assignment> */
class AssignmentFactory extends Factory
{
    public function definition(): array
    {
        return ['trip_id' => Trip::factory(), 'rank' => 'CPT', 'seat_number' => 1, 'crew_member_id' => CrewMember::factory(), 'source' => 'auto'];
    }
}
