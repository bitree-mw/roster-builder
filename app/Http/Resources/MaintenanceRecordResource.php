<?php

namespace App\Http\Resources;

use App\Models\MaintenanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,
            'aircraft_id' => $this->aircraft_id,
            'aircraft' => $this->whenLoaded('aircraft', fn (): array => [
                'id' => $this->aircraft->id,
                'registration' => $this->aircraft->registration,
                'type' => $this->aircraft->relationLoaded('aircraftType') ? $this->aircraft->aircraftType->code : null,
                'status' => $this->aircraft->status,
                'airframe_hours' => $this->aircraft->airframe_hours,
            ]),
            'kind' => $this->kind,
            'kind_label' => MaintenanceRecord::KINDS[$this->kind] ?? $this->kind,
            'title' => $this->title,
            'performed_on' => $this->performed_on->format('Y-m-d'),
            'airframe_hours_at' => $this->airframe_hours_at,
            'next_due_on' => $this->next_due_on?->format('Y-m-d'),
            'next_due_hours' => $this->next_due_hours,
            'notes' => $this->notes,
            'recorded_by' => $this->whenLoaded('recorder', fn (): ?string => $this->recorder?->name),
        ];
    }
}
