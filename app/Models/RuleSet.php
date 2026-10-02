<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'report_before_min', 'release_after_min', 'max_duty_day_h', 'min_rest_h', 'max_duty_7d_h', 'max_block_month_h', 'max_consecutive_days', 'min_days_off_month', 'max_days_off_week', 'utc_offset_minutes'])]
class RuleSet extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['report_before_min' => 'integer', 'release_after_min' => 'integer', 'max_duty_day_h' => 'float', 'min_rest_h' => 'float', 'max_duty_7d_h' => 'float', 'max_block_month_h' => 'float', 'max_consecutive_days' => 'integer', 'min_days_off_month' => 'integer', 'max_days_off_week' => 'integer', 'utc_offset_minutes' => 'integer'];
    }
}
