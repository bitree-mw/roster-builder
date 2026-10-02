<?php

namespace Database\Factories;

use App\Models\RosterPeriod;
use App\Models\RuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RosterPeriod> */
class RosterPeriodFactory extends Factory
{
    /**
     * Default attributes for a roster period.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['month' => '2026-10-01', 'status' => 'draft', 'rules_snapshot' => RuleSet::factory()->make()->toArray()];
    }
}
