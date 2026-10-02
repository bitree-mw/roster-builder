<?php

namespace App\Services;

use App\Models\Aircraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AircraftService
{
    public function __construct(private AuditService $audit) {}

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

    public function changeStatus(Aircraft $aircraft, string $status, ?string $reason, User $actor): Aircraft
    {
        return DB::transaction(function () use ($aircraft, $status, $reason, $actor): Aircraft {
            $aircraft = Aircraft::query()->lockForUpdate()->findOrFail($aircraft->id);
            $before = $aircraft->toArray();
            $aircraft->status = $status;
            $aircraft->status_reason = filled($reason) ? trim($reason) : null;
            $aircraft->status_changed_at = now();
            $aircraft->save();
            $this->audit->record($actor, 'status_changed', $aircraft, $before, $aircraft->toArray());

            return $aircraft->load('aircraftType');
        });
    }

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
