<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates planning a day item for a crew member over a date range (at most 31 days): leave, protected day
 * off, simulator or standby. SIM and standby may have base-local start/end times (then they count as duty);
 * leave and days off are always whole days.
 */
class CrewActivityRequest extends FormRequest
{
    /**
     * Staff with write access.
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
        return [
            'crew_member_id' => ['required', 'integer', 'exists:crew_members,id'],
            'type' => ['required', Rule::in(['leave', 'day_off', 'sim', 'standby'])],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:'.$this->rangeLimit()],
            'starts_local' => ['nullable', 'required_with:ends_local', 'prohibited_if:type,leave', 'prohibited_if:type,day_off', 'date_format:H:i'],
            'ends_local' => ['nullable', 'required_with:starts_local', 'date_format:H:i', 'different:starts_local'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_to.before_or_equal' => 'Plan at most 31 days at a time.',
            'starts_local.prohibited_if' => 'Leave and days off are whole days; times are only for SIM and standby.',
            'starts_local.date_format' => 'Enter times on the 24-hour clock as HH:MM, for example 06:00 or 18:30.',
            'ends_local.date_format' => 'Enter times on the 24-hour clock as HH:MM, for example 06:00 or 18:30.',
        ];
    }

    /** The last allowed end date: 30 days after the start (or the start itself when it is not a date). */
    private function rangeLimit(): string
    {
        $from = strtotime((string) $this->input('date_from'));

        return $from ? date('Y-m-d', $from + 30 * 86400) : '1970-01-01';
    }
}
