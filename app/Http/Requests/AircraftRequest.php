<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates airframe create/update. Status is not accepted here; it has its own endpoint.
 */
class AircraftRequest extends FormRequest
{
    /**
     * Staff with write access only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    /**
     * Registrations are stored trimmed and upper-case ("7q-tba " -> "7Q-TBA").
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('registration'))) {
            $this->merge(['registration' => strtoupper(trim($this->input('registration')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'registration' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9-]+$/', Rule::unique('aircraft')->ignore($this->route('aircraft'))],
            'aircraft_type_id' => ['required', 'integer', 'exists:aircraft_types,id'],
            'airframe_hours' => ['required', 'numeric', 'between:0,999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
