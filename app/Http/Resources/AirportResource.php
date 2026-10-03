<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of an airport. "id" repeats the code so generic edit/delete helpers can address
 * /airports/{id}. Usage counts are only present when the query loaded them (the admin list).
 */
class AirportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->code,
            'code' => $this->code,
            'name' => $this->name,
            'utc_offset_minutes' => $this->utc_offset_minutes,
            'is_base' => $this->is_base,
            'crew_count' => $this->whenCounted('crewMembers'),
            // Legs that depart from or arrive at the airport: any use blocks deletion.
            'leg_count' => $this->when(isset($this->departing_legs_count, $this->arriving_legs_count), fn (): int => $this->departing_legs_count + $this->arriving_legs_count),
        ];
    }
}
