<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FlightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('flights')->ignore($this->route('flight'))],
            'aircraft_type_id' => ['required', 'integer', 'exists:aircraft_types,id'],
            'active' => ['required', 'boolean'],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'legs' => ['required', 'array', 'min:1', 'max:40'],
            'legs.*' => ['array:trip_day,from_airport,to_airport,departs_local,arrives_local'],
            'legs.*.trip_day' => ['required', 'integer', 'between:1,4'],
            'legs.*.from_airport' => ['required', 'exists:airports,code'],
            'legs.*.to_airport' => ['required', 'exists:airports,code'],
            'legs.*.departs_local' => ['required', 'date_format:H:i'],
            'legs.*.arrives_local' => ['required', 'date_format:H:i'],
        ];
    }
}
