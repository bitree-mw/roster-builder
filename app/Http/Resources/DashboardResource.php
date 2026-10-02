<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of the operations dashboard (DashboardService::summary()): overview counts, roster weeks,
 * live conflicts, today's trips and the fleet, maintenance and document items that need attention.
 *
 * @property array<string, mixed> $resource
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'today' => $this->resource['today'],
            'overview' => (new OverviewResource($this->resource['overview']))->toArray($request),
            'weeks' => $this->resource['weeks'],
            'conflicts' => $this->resource['conflicts'],
            'conflict_total' => $this->resource['conflict_total'],
            'today_trips' => $this->resource['today_trips'],
            'fleet_issues' => $this->resource['fleet_issues'],
            'maintenance_alerts' => MaintenanceAlertResource::collection($this->resource['maintenance_alerts'])->toArray($request),
            'document_alerts' => $this->resource['document_alerts'],
        ];
    }
}
