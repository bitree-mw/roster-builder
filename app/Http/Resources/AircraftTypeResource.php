<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of an aircraft type. Airframe counts are only present when the query loaded them.
 */
class AircraftTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,
            'code' => $this->code,
            'cabin_crew_required' => $this->cabin_crew_required,
            'palette' => $this->palette,
            'aircraft_count' => $this->whenCounted('aircraft'),
            'available_aircraft_count' => $this->whenCounted('available_aircraft'),
        ];
    }
}
