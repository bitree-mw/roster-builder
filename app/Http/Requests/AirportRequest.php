<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an airport (route destination) added or edited by an administrator. The code is the airport's
 * identity — flights, crew bases and backups refer to it — so it can only be set when the airport is created;
 * an edit changes the name, UTC offset and crew-base flag.
 */
class AirportRequest extends FormRequest
{
    /**
     * Administrators only (Accounts / Airports panel).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-airports') ?? false;
    }

    /**
     * Codes and names are stored trimmed; codes upper-case ("ebb" → "EBB").
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * The code is only accepted when creating (POST); on an edit it is not part of the validated data.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            // Offset from UTC in minutes (Entebbe: +180), in quarter hours as real time zones are.
            'utc_offset_minutes' => ['required', 'integer', 'between:-720,840', 'multiple_of:15'],
            'is_base' => ['required', 'boolean'],
        ];
        if ($this->isMethod('POST')) {
            $rules['code'] = ['required', 'string', 'regex:/^[A-Z]{3,4}$/', 'unique:airports,code'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use the 3-letter IATA code (or 4-letter ICAO code), for example EBB.',
            'code.unique' => 'An airport with this code already exists.',
            'utc_offset_minutes.multiple_of' => 'The UTC offset must be in quarter hours, for example +3 or +5:45.',
        ];
    }
}
