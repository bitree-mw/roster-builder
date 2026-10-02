<?php

namespace Database\Factories;

use App\Models\CrewMember;
use App\Models\EmailLog;
use App\Models\RosterPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailLog> */
class EmailLogFactory extends Factory
{
    public function definition(): array
    {
        return ['crew_member_id' => CrewMember::factory(), 'roster_period_id' => RosterPeriod::factory(), 'status' => 'queued'];
    }
}
