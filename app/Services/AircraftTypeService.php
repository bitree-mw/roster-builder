<?php

namespace App\Services;

use App\Models\AircraftType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business operations for aircraft types (create, update, delete) with audit.
 */
class AircraftTypeService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Create a type, or update the given one, and audit the change in the same transaction.
     *
     * @param  array{code: string, cabin_crew_required: int, palette: string}  $data
     */
    public function save(array $data, User $actor, ?AircraftType $aircraft = null): AircraftType
    {
        return DB::transaction(function () use ($data, $actor, $aircraft): AircraftType {
            $aircraft ??= new AircraftType;
            $before = $aircraft->exists ? $aircraft->toArray() : null;
            $aircraft->fill($data)->save();
            $this->audit->record($actor, $before ? 'updated' : 'created', $aircraft, $before, $aircraft->toArray());

            return $aircraft;
        });
    }

    /**
     * Delete a type unless flights, crew ratings or airframes still refer to it. The row is locked first so a
     * concurrent assignment cannot slip in between the check and the delete.
     *
     * @throws ValidationException when the type is still in use
     */
    public function delete(AircraftType $aircraft, User $actor): void
    {
        DB::transaction(function () use ($aircraft, $actor): void {
            $aircraft = AircraftType::query()->lockForUpdate()->findOrFail($aircraft->id);
            if ($aircraft->flights()->exists() || $aircraft->crewMembers()->exists() || $aircraft->aircraft()->exists()) {
                throw ValidationException::withMessages(['aircraft_type' => 'This aircraft type is used by flights, crew ratings or registered aircraft and cannot be removed.']);
            }
            $this->audit->record($actor, 'deleted', $aircraft, $aircraft->toArray());
            $aircraft->delete();
        });
    }
}
