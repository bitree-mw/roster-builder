<?php

namespace Database\Factories;

use App\Models\RosterPeriod;
use App\Models\RuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RosterPeriod> */
class RosterPeriodFactory extends Factory
{
    /**
     * Default attributes for a draft roster week (Monday to Sunday).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['starts_on' => '2026-10-05', 'ends_on' => '2026-10-11', 'status' => 'draft', 'rules_snapshot' => RuleSet::factory()->make()->toArray()];
    }
}
