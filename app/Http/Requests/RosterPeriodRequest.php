<?php

namespace App\Http\Requests;

use App\Models\RosterPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates creating a roster period: its length (one week, two weeks or a month) and first day — a
 * Monday for weeks and fortnights, the 1st for a month — inside the planning window (config/roster.php,
 * about six months back to two years ahead).
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $monday = now()->startOfWeek(CarbonImmutable::MONDAY);

        return [
            'starts_on' => ['required', 'date_format:Y-m-d',
                'after_or_equal:'.$monday->copy()->subWeeks((int) config('roster.planning_weeks_back'))->startOfMonth()->format('Y-m-d'),
                'before_or_equal:'.$monday->copy()->addWeeks((int) config('roster.planning_weeks_ahead'))->format('Y-m-d'),
            ],
            'length' => ['required', Rule::in(array_keys(RosterPeriod::LENGTHS))],
        ];
    }

    /**
     * Weeks and fortnights start on a Monday; a month starts on the 1st.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $start = CarbonImmutable::parse($this->input('starts_on'));
            if ($this->input('length') === 'month' && $start->day !== 1) {
                $validator->errors()->add('starts_on', 'A monthly roster starts on the 1st of the month.');
            } elseif ($this->input('length') !== 'month' && $start->dayOfWeekIso !== 1) {
                $validator->errors()->add('starts_on', 'Weekly and two-week rosters start on a Monday.');
            }
        }];
    }
}
