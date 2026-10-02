<?php

namespace App\Services;

use App\Models\Aircraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business operations for individual airframes: registration details, availability status and deletion.
 */
class AircraftService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Register or edit an airframe. New airframes always start "available"; status is only ever changed
     * through changeStatus() so every change carries a reason and its own audit entry.
     *
     * @param  array{registration: string, aircraft_type_id: int, airframe_hours: float|int, notes?: string|null}  $data
     */
    public function save(array $data, User $actor, ?Aircraft $aircraft = null): Aircraft
    {
        return DB::transaction(function () use ($data, $actor, $aircraft): Aircraft {
            $aircraft ??= new Aircraft;
            $before = $aircraft->exists ? $aircraft->toArray() : null;
            $aircraft->fill($data);
            if (! $aircraft->exists) {
                $aircraft->status = 'available';
                $aircraft->status_changed_at = now();
            }
            $aircraft->save();
            $this->audit->record($actor, $before ? 'updated' : 'created', $aircraft, $before, $aircraft->toArray());

            return $aircraft->load('aircraftType');
        });
    }

    /**
     * Mark an airframe available, in maintenance, grounded (AOG) or unavailable. The row is locked so two people
     * changing status at once cannot overwrite each other silently; the change time is stamped for display.
     */
    public function changeStatus(Aircraft $aircraft, string $status, ?string $reason, User $actor): Aircraft
    {
        return DB::transaction(function () use ($aircraft, $status, $reason, $actor): Aircraft {
            $aircraft = Aircraft::query()->lockForUpdate()->findOrFail($aircraft->id);
            $before = $aircraft->toArray();
            $aircraft->status = $status;
            // A blank reason (e.g. when returning to service) clears any previous grounding reason.
            $aircraft->status_reason = filled($reason) ? trim($reason) : null;
            $aircraft->status_changed_at = now();
            $aircraft->save();
            $this->audit->record($actor, 'status_changed', $aircraft, $before, $aircraft->toArray());

            return $aircraft->load('aircraftType');
        });
    }

    /**
     * Delete an airframe only when it has no maintenance history; otherwise it must be marked unavailable so the
     * records stay traceable.
     *
     * @throws ValidationException when maintenance records exist
     */
    public function delete(Aircraft $aircraft, User $actor): void
    {
        DB::transaction(function () use ($aircraft, $actor): void {
            $aircraft = Aircraft::query()->lockForUpdate()->findOrFail($aircraft->id);
            if ($aircraft->maintenanceRecords()->exists()) {
                throw ValidationException::withMessages(['aircraft' => 'This aircraft has maintenance history. Mark it unavailable instead.']);
            }
            $this->audit->record($actor, 'deleted', $aircraft, $aircraft->toArray());
            $aircraft->delete();
        });
    }
}
