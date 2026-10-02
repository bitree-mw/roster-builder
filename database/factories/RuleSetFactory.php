<?php

namespace Database\Factories;

use App\Models\RuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RuleSet> */
class RuleSetFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Standard', 'report_before_min' => 60, 'release_after_min' => 30, 'max_duty_day_h' => 13, 'min_rest_h' => 12, 'max_duty_7d_h' => 60, 'max_block_month_h' => 100, 'max_consecutive_days' => 5, 'min_days_off_month' => 8, 'max_days_off_week' => 4, 'utc_offset_minutes' => 120];
    }
}
