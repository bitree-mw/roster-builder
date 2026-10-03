<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the duty rule set. Ranges are sanity limits, not regulatory values.
 */
class RuleSetRequest extends FormRequest
{
    /**
     * Schedulers only (crew control can read but not change rules).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-rules') ?? false;
    }

    /**
     * The base offset must be a whole quarter hour.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'report_before_min' => ['required', 'integer', 'between:0,240'],
            'release_after_min' => ['required', 'integer', 'between:0,240'],
            'max_duty_day_h' => ['required', 'numeric', 'between:1,24'],
            'min_rest_h' => ['required', 'numeric', 'between:1,48'],
            'max_duty_7d_h' => ['required', 'numeric', 'between:1,168'],
            'max_block_month_h' => ['required', 'numeric', 'between:1,744'],
            'max_consecutive_days' => ['required', 'integer', 'between:1,31'],
            'min_days_off_month' => ['required', 'integer', 'between:0,28'],
            'max_days_off_week' => ['required', 'integer', 'between:0,7'],
            // Crew with a document date not on record: roster with a warning, or never roster automatically.
            'missing_documents' => ['required', 'in:warn,block'],
            'utc_offset_minutes' => ['required', 'integer', 'between:-720,840', 'multiple_of:15'],
        ];
    }
}
