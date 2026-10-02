<?php

namespace App\Http\Resources;

use App\Models\MaintenanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property array{record: MaintenanceRecord, state: string, days_remaining: ?int, hours_remaining: ?float} $resource */
class MaintenanceAlertResource extends JsonResource
{
    /**
     * One due item from MaintenanceService: state (overdue, due_soon, ok), days/hours remaining and the record.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'state' => $this->resource['state'],
            'days_remaining' => $this->resource['days_remaining'],
            'hours_remaining' => $this->resource['hours_remaining'],
            'record' => new MaintenanceRecordResource($this->resource['record']),
        ];
    }
}
