<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates aircraft type create/update: unique upper-case code, cabin complement 0–20, a known palette key.
 */
class AircraftTypeRequest extends FormRequest
{
    /**
     * Staff with write access only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    /**
     * Codes are stored trimmed and upper-case.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('aircraft_types')->ignore($this->route('aircraft_type'))],
            'cabin_crew_required' => ['required', 'integer', 'between:0,20'],
            'palette' => ['required', Rule::in(['forest', 'gold', 'sky', 'plum', 'coral'])],
        ];
    }
}
