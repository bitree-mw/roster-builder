<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AircraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('registration'))) {
            $this->merge(['registration' => strtoupper(trim($this->input('registration')))]);
        }
    }

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
