<?php

namespace App\Http\Resources;

use App\Models\Aircraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of an airframe, including a human status label and (when attached) its maintenance due items.
 */
class AircraftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,
            'registration' => $this->registration,
            'aircraft_type_id' => $this->aircraft_type_id,
            'aircraft_type' => new AircraftTypeResource($this->whenLoaded('aircraftType')),
            'status' => $this->status,
            'status_label' => Aircraft::STATUSES[$this->status] ?? $this->status,
            'status_reason' => $this->status_reason,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'airframe_hours' => $this->airframe_hours,
            'notes' => $this->notes,
            'maintenance_due' => MaintenanceAlertResource::collection($this->whenLoaded('dueItems')),
        ];
    }
}
