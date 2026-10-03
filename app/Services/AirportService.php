<?php

namespace App\Services;

use App\Models\Airport;
use App\Models\FlightLeg;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business operations for airports (route destinations and crew bases): create, edit and remove, each in a
 * transaction with an audit entry. Airports are keyed by code, so audit rows are written as "airports" events
 * carrying the code. An airport stays a base while crew or flights depend on it, and is never removed while
 * a flight leg or crew base refers to it.
 */
class AirportService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Add an airport so it can be chosen on flight routes (and, as a base, for crew).
     *
     * @param  array{code: string, name: string, utc_offset_minutes: int, is_base: bool}  $data
     */
    public function create(array $data, User $actor): Airport
    {
        return DB::transaction(function () use ($data, $actor): Airport {
            $airport = Airport::create($data);
            $this->audit->event($actor, 'created', 'airports', $airport->toArray());

            return $airport;
        });
    }

    /**
     * Change an airport's name, UTC offset or base flag. Removing the base flag is refused while crew are based
     * there or a flight route starts there (routes must start and end at a base).
     *
     * @param  array{name: string, utc_offset_minutes: int, is_base: bool}  $data
     *
     * @throws ValidationException when the airport must stay a base
     */
    public function update(Airport $airport, array $data, User $actor): Airport
    {
        return DB::transaction(function () use ($airport, $data, $actor): Airport {
            $airport = Airport::query()->lockForUpdate()->findOrFail($airport->code);
            $before = $airport->toArray();
            if ($airport->is_base && ! $data['is_base']) {
                $crew = $airport->crewMembers()->count();
                $routes = FlightLeg::query()->where('trip_day', 1)->where('sequence', 1)->where('from_airport', $airport->code)->count();
                if ($crew > 0 || $routes > 0) {
                    throw ValidationException::withMessages(['is_base' => "{$crew} crew members are based at {$airport->code} and {$routes} flight routes start there. Move them before {$airport->code} stops being a crew base."]);
                }
            }
            $airport->fill($data)->save();
            $this->audit->event($actor, 'updated', 'airports', ['code' => $airport->code, 'before' => $before, 'after' => $airport->toArray()]);

            return $airport;
        });
    }

    /**
     * Remove an airport nothing refers to (for example one added by mistake). The row is locked first so a
     * flight or crew member cannot start using it between the check and the delete.
     *
     * @throws ValidationException when crew or flight legs still use it
     */
    public function delete(Airport $airport, User $actor): void
    {
        DB::transaction(function () use ($airport, $actor): void {
            $airport = Airport::query()->lockForUpdate()->findOrFail($airport->code);
            if ($airport->crewMembers()->exists() || $airport->departingLegs()->exists() || $airport->arrivingLegs()->exists()) {
                throw ValidationException::withMessages(['airport' => "{$airport->code} is used by flight routes or as a crew base and cannot be removed."]);
            }
            $this->audit->event($actor, 'deleted', 'airports', $airport->toArray());
            $airport->delete();
        });
    }
}
