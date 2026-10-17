<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates crew control's choice of plane for a trip: a registered airframe, or null to let the roster build
 * choose from the flight plan's planes again. Whether the trip can still change is decided by TripAircraftService.
 */
class TripAircraftRequest extends FormRequest
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
        return ['aircraft_id' => ['present', 'nullable', 'integer', 'exists:aircraft,id']];
    }
}
