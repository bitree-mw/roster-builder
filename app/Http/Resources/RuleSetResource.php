<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of the duty rule set.
 */
class RuleSetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,
            'name' => $this->name,
            'report_before_min' => $this->report_before_min,
            'release_after_min' => $this->release_after_min,
            'max_duty_day_h' => $this->max_duty_day_h,
            'min_rest_h' => $this->min_rest_h,
            'max_duty_7d_h' => $this->max_duty_7d_h,
            'max_block_month_h' => $this->max_block_month_h,
            'max_consecutive_days' => $this->max_consecutive_days,
            'min_days_off_month' => $this->min_days_off_month,
            'max_days_off_week' => $this->max_days_off_week,
            'utc_offset_minutes' => $this->utc_offset_minutes,
        ];
    }
}
