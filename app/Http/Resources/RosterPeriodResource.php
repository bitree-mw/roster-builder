<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of a roster period. Trips and assignments are included only when loaded, already filtered by
 * the controller for crew users.
 */
class RosterPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,
            'month' => $this->month->format('Y-m'),
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'trips' => $this->whenLoaded('trips', fn (): array => $this->trips->map(fn ($trip): array => [
                'id' => $trip->id, 'start_date' => $trip->start_date->format('Y-m-d'), 'schedule' => $trip->schedule_snapshot,
                'assignments' => $trip->assignments->map(fn ($assignment): array => [
                    'id' => $assignment->id, 'rank' => $assignment->rank, 'seat_number' => $assignment->seat_number, 'crew_member_id' => $assignment->crew_member_id,
                    'source' => $assignment->source, 'flag_reasons' => $assignment->flag_reasons,
                ])->all(),
            ])->all()),
        ];
    }
}
