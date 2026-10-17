<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Airports, aircraft types and registered planes for dropdowns. Wraps an array:
 * ['airports' => ..., 'aircraft_types' => ..., 'aircraft' => ...] (aircraft with aircraftType loaded).
 */
class LookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['airports' => $this->resource['airports']->map(fn ($airport): array => ['code' => $airport->code, 'name' => $airport->name, 'is_base' => $airport->is_base])->all(),
            'aircraft_types' => AircraftTypeResource::collection($this->resource['aircraft_types']),
            // Registered planes for the flight plan form: "7Q-TBA · Boeing 737-800 (B737)" with type and status.
            'aircraft' => $this->resource['aircraft']->map(fn ($plane): array => ['id' => $plane->id, 'registration' => $plane->registration, 'label' => $plane->label(),
                'aircraft_type_id' => $plane->aircraft_type_id, 'aircraft_type' => $plane->aircraftType?->code, 'status' => $plane->status])->all()];
    }
}
