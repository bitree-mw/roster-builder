<?php

namespace App\Http\Resources;

use App\Models\RosterPeriod;
use App\Services\RosterPeriodService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of a roster week for the week timeline: dates, status, build/publication times and, when the
 * controller counted them, seat totals. editable is false once the week is published or has ended.
 *
 * @mixin RosterPeriod
 */
class RosterPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,
            'starts_on' => $this->starts_on->format('Y-m-d'),
            'ends_on' => $this->ends_on->format('Y-m-d'),
            'iso_week' => $this->starts_on->isoWeek,
            'length' => $this->length,
            'days' => count($this->dates()),
            'label' => ucfirst($this->label()),
            'status' => $this->status,
            'built_at' => $this->built_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'editable' => app(RosterPeriodService::class)->editable($this->resource),
            'ended' => app(RosterPeriodService::class)->ended($this->resource),
            'trips_count' => $this->whenCounted('trips'),
            'seats_count' => $this->whenHas('seats_count', fn (): int => (int) $this->seats_count),
            'open_seats_count' => $this->whenHas('open_seats_count', fn (): int => (int) $this->open_seats_count),
        ];
    }
}
