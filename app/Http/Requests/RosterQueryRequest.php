<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Date range for the week timeline (GET /roster-periods?from=&to=). Staff and crew with a linked profile may
 * read; the range is limited so one request never loads an unbounded history.
 */
class RosterQueryRequest extends FormRequest
{
    /**
     * Staff, or crew accounts linked to a crew member.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('read-rosters') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * At most config('roster.max_weeks_listed') weeks per request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->filled(['from', 'to'])
                && CarbonImmutable::parse($this->input('from'))->diffInWeeks(CarbonImmutable::parse($this->input('to'))) > (int) config('roster.max_weeks_listed')) {
                $validator->errors()->add('to', 'Choose a range of at most '.config('roster.max_weeks_listed').' weeks.');
            }
        }];
    }
}
