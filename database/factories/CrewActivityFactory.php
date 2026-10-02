<?php

namespace Database\Factories;

use App\Models\CrewActivity;
use App\Models\CrewMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CrewActivity> */
class CrewActivityFactory extends Factory
{
    public function definition(): array
    {
        return ['crew_member_id' => CrewMember::factory(), 'date' => '2026-10-10', 'type' => 'leave'];
    }
}
