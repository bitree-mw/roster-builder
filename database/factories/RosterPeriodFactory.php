<?php

namespace Database\Factories;

use App\Models\RosterPeriod;
use App\Models\RuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RosterPeriod> */
class RosterPeriodFactory extends Factory
{
    public function definition(): array
    {
        return ['month' => '2026-10-01', 'status' => 'draft', 'rules_snapshot' => RuleSet::factory()->make()->toArray()];
    }
}
