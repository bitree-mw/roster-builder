<?php

namespace Database\Factories;

use App\Models\CrewMember;
use App\Models\Exclusion;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Exclusion> */
class ExclusionFactory extends Factory
{
    public function definition(): array
    {
        return ['trip_id' => Trip::factory(), 'crew_member_id' => CrewMember::factory()];
    }
}
