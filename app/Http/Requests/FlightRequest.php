<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the shape of a flight pattern. Timeline rules (connections, base return, duty and rest) are
 * checked afterwards by FlightTimelineService because they depend on the configured rules.
 */
class FlightRequest extends FormRequest
{
    /**
     * Staff with write access only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    /**
     * Flight codes are stored trimmed and upper-case.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * Legs only accept the listed keys, so a client cannot inject a sequence or flight id.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('flights')->ignore($this->route('flight'))],
            'aircraft_type_id' => ['required', 'integer', 'exists:aircraft_types,id'],
            'active' => ['required', 'boolean'],
            // Zone of the leg times in this payload: "utc" (GMT, as the Flight routes page sends) or "local" (base time).
            'time_zone' => ['sometimes', 'in:local,utc'],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'legs' => ['required', 'array', 'min:1', 'max:40'],
            'legs.*' => ['array:trip_day,from_airport,to_airport,departs_local,arrives_local'],
            // Trip day 2+ follows a night stop; rotations may run up to roster.max_trip_days.
            'legs.*.trip_day' => ['required', 'integer', 'between:1,'.config('roster.max_trip_days')],
            'legs.*.from_airport' => ['required', 'exists:airports,code'],
            'legs.*.to_airport' => ['required', 'exists:airports,code'],
            'legs.*.departs_local' => ['required', 'date_format:H:i'],
            'legs.*.arrives_local' => ['required', 'date_format:H:i'],
        ];
    }
}
