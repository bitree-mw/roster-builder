<?php

namespace App\Http\Resources;

use App\Models\RuleSet;
use App\Services\FlightTimelineService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of a flight pattern with legs (base-local and GMT times), weekdays, block minutes, its planned
 * aircraft type and the planes that can fly it.
 */
class FlightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $offset = once(fn (): int => (int) (RuleSet::query()->whereKey(1)->value('utc_offset_minutes') ?? 120));
        $timeline = app(FlightTimelineService::class);

        return ['id' => $this->id,
            'code' => $this->code,
            'aircraft_type_id' => $this->aircraft_type_id,
            'active' => $this->active,
            'aircraft' => new AircraftTypeResource($this->whenLoaded('aircraftType')),
            // Planes that can fly the plan, in order of preference, shown as "7Q-TBA · Boeing 737-800 (B737)".
            'aircraft_ids' => $this->whenLoaded('aircraft', fn (): array => $this->aircraft->pluck('id')->all()),
            'planes' => $this->whenLoaded('aircraft', fn (): array => $this->aircraft->map(fn ($plane): array => [
                'id' => $plane->id, 'registration' => $plane->registration, 'label' => $plane->label(),
                'aircraft_type' => $plane->aircraftType?->code, 'palette' => $plane->aircraftType?->palette, 'status' => $plane->status,
            ])->all()),
            'weekdays' => $this->whenLoaded('days', fn (): array => $this->days->pluck('weekday')->all()),
            'block_minutes' => $this->whenLoaded('legs', fn (): int => $this->legs->sum(fn ($leg): int => $this->blockMinutes($leg->departs_local, $leg->arrives_local))),
            'legs' => $this->whenLoaded('legs', fn (): array => $this->legs->map(fn ($leg): array => [
                'trip_day' => $leg->trip_day, 'sequence' => $leg->sequence, 'from_airport' => $leg->from_airport, 'to_airport' => $leg->to_airport,
                'departs_local' => substr($leg->departs_local, 0, 5), 'arrives_local' => substr($leg->arrives_local, 0, 5),
                // The same times in GMT (base local minus the rules' offset), as shown on the Flight routes page.
                'departs_utc' => $timeline->shiftClock($leg->departs_local, -$offset), 'arrives_utc' => $timeline->shiftClock($leg->arrives_local, -$offset),
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
