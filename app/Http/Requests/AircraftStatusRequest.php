<?php

namespace App\Http\Requests;

use App\Models\Aircraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an availability change. The fleet page offers only Available / Not available; the reason is an
 * optional note so marking an aircraft unavailable stays a one-click action.
 */
class AircraftStatusRequest extends FormRequest
{
    /**
     * Staff with write access only.
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
            'status' => ['required', Rule::in(array_keys(Aircraft::STATUSES))],
            // Optional: a short note on why the aircraft cannot be used (shown on the card and in the audit log).
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
