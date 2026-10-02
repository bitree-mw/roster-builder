<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates enabling/disabling a flight pattern.
 */
class FlightStatusRequest extends FormRequest
{
    /**
     * Staff with write access only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
    }
}
