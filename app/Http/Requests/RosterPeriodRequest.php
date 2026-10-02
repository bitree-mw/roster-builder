<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates creating a roster week: a Monday inside the planning window (config/roster.php, about six months
 * back to two years ahead of the current week).
 */
class RosterPeriodRequest extends FormRequest
{
    /**
     * Staff with write access only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $monday = now()->startOfWeek(CarbonImmutable::MONDAY);

        return ['week_start' => ['required', 'date_format:Y-m-d',
            'after_or_equal:'.$monday->copy()->subWeeks((int) config('roster.planning_weeks_back'))->format('Y-m-d'),
            'before_or_equal:'.$monday->copy()->addWeeks((int) config('roster.planning_weeks_ahead'))->format('Y-m-d'),
        ]];
    }

    /**
     * Rosters run Monday to Sunday, so the week must start on a Monday.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && CarbonImmutable::parse($this->input('week_start'))->dayOfWeekIso !== 1) {
                $validator->errors()->add('week_start', 'A roster week must start on a Monday.');
            }
        }];
    }
}
