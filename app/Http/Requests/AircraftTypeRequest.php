<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AircraftTypeRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('aircraft_types')->ignore($this->route('aircraft_type'))],
            'cabin_crew_required' => ['required', 'integer', 'between:0,20'],
            'palette' => ['required', Rule::in(['forest', 'gold', 'sky', 'plum', 'coral'])],
        ];
    }
}
