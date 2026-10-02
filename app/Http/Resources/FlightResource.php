<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of a flight pattern with legs, weekdays, block minutes and its aircraft type.
 */
class FlightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,
            'code' => $this->code,
            'aircraft_type_id' => $this->aircraft_type_id,
            'active' => $this->active,
            'aircraft' => new AircraftTypeResource($this->whenLoaded('aircraftType')),
            'weekdays' => $this->whenLoaded('days', fn (): array => $this->days->pluck('weekday')->all()),
            'block_minutes' => $this->whenLoaded('legs', fn (): int => $this->legs->sum(fn ($leg): int => $this->blockMinutes($leg->departs_local, $leg->arrives_local))),
            'legs' => $this->whenLoaded('legs', fn (): array => $this->legs->map(fn ($leg): array => [
                'trip_day' => $leg->trip_day, 'sequence' => $leg->sequence, 'from_airport' => $leg->from_airport, 'to_airport' => $leg->to_airport,
                'departs_local' => substr($leg->departs_local, 0, 5), 'arrives_local' => substr($leg->arrives_local, 0, 5),
                'block_minutes' => $this->blockMinutes($leg->departs_local, $leg->arrives_local),
            ])->all()),
        ];
    }

    /** Base-local wall-clock block time; an arrival at or before departure rolls to the next day. */
    private function blockMinutes(string $departs, string $arrives): int
    {
        $minutes = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $block = $minutes($arrives) - $minutes($departs);

        return $block > 0 ? $block : $block + 1440;
    }
}
