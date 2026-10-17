<?php

namespace App\Services;

use App\Models\Aircraft;
use App\Models\RosterPeriod;
use App\Models\Trip;
use App\Models\User;
use App\Support\Roster\Duty;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crew control's choice of plane for one dated trip. Any registered airframe may be chosen, of any type; the
 * trip then follows that plane's type (cabin crew seats are added or removed, pilot ratings are checked live
 * as conflicts). A chosen plane is kept on rebuilds (aircraft_source "manual"); clearing the choice hands the
 * trip back to the roster build, which picks from the flight plan's planes.
 */
class TripAircraftService
{
    public function __construct(
        private AuditService $audit,
        private ExpiryService $expiry,
        private RosterPeriodService $periods,
        private RosterScheduleService $schedule,
        private RosterBuilderService $builder,
    ) {}

    /**
     * The planes crew control can choose from for a trip: the flight plan's own planes first (in its order),
     * then the rest of the fleet, each with its status and any other trip it is already flying at that time.
     *
     * @return array<int, array{id: int, registration: string, label: string, aircraft_type: ?string, cabin_crew_required: int, status: string, status_label: string, listed: bool, current: bool, busy_with: ?string}>
     */
    public function options(Trip $trip): array
    {
        $trip->loadMissing('flight.aircraft');
        $listed = $trip->flight->aircraft->pluck('id')->values()->flip();
        $start = Duty::minutes($trip->schedule_snapshot['report']);
        $end = Duty::minutes($trip->schedule_snapshot['release']);
        $days = (int) config('roster.max_trip_days');
        // Other trips flying at the same time, by plane: "LB2 on 21 Oct".
        $busy = [];
        $others = Trip::query()->whereNotNull('aircraft_id')->whereKeyNot($trip->id)
            ->whereBetween('start_date', [$trip->start_date->subDays($days)->format('Y-m-d'), $trip->start_date->addDays($days)->format('Y-m-d')])
            ->get(['id', 'aircraft_id', 'start_date', 'schedule_snapshot']);
        foreach ($others as $other) {
            $snapshot = $other->schedule_snapshot;
            if (isset($snapshot['report'], $snapshot['release']) && Duty::minutes($snapshot['report']) < $end && $start < Duty::minutes($snapshot['release'])) {
                $busy[$other->aircraft_id] ??= ($snapshot['code'] ?? 'Trip').' on '.$other->start_date->format('d M');
            }
        }

        return Aircraft::query()->with('aircraftType')->orderBy('registration')->get()
            ->sortBy(fn (Aircraft $plane): array => [$listed->has($plane->id) ? 0 : 1, $listed->get($plane->id, 0), $plane->registration])
            ->map(fn (Aircraft $plane): array => [
                'id' => $plane->id, 'registration' => $plane->registration, 'label' => $plane->label(),
                'aircraft_type' => $plane->aircraftType?->code, 'cabin_crew_required' => (int) ($plane->aircraftType?->cabin_crew_required ?? 0),
                'status' => $plane->status, 'status_label' => Aircraft::STATUSES[$plane->status] ?? $plane->status,
                'listed' => $listed->has($plane->id), 'current' => $plane->id === $trip->aircraft_id, 'busy_with' => $busy[$plane->id] ?? null,
            ])->values()->all();
    }

    /**
     * Put a plane on a trip (or clear the choice with null). The trip's snapshot takes the plane's type, seats
     * follow its cabin requirement, and the change is audited, all in one transaction under the roster lock.
     *
     * @throws ValidationException when the roster cannot change or the trip has already operated
     */
    public function change(Trip $trip, ?int $aircraftId, User $actor): Trip
    {
        return DB::transaction(function () use ($trip, $aircraftId, $actor): Trip {
            $period = RosterPeriod::query()->lockForUpdate()->findOrFail($trip->roster_period_id);
            $this->periods->assertEditable($period);
            $trip = Trip::query()->with('flight.aircraftType')->findOrFail($trip->id);
            if ($trip->start_date->lt($this->expiry->today())) {
                throw ValidationException::withMessages(['aircraft_id' => 'This trip has already operated and can no longer be changed.']);
            }
            $plane = $aircraftId !== null ? Aircraft::query()->with('aircraftType')->findOrFail($aircraftId) : null;
            $before = ['aircraft_id' => $trip->aircraft_id, 'aircraft_source' => $trip->aircraft_source, 'aircraft' => $trip->schedule_snapshot['aircraft_label'] ?? null];
            $trip->schedule_snapshot = [...$trip->schedule_snapshot, ...$this->schedule->aircraftFields($plane, $trip->flight->aircraftType)];
            $trip->forceFill(['aircraft_id' => $plane?->id, 'aircraft_source' => $plane !== null ? 'manual' : 'auto'])->save();
            // Cabin seats follow the plane's type (a B737 needs more cabin crew than a Q400).
            $this->builder->syncSeats($trip);
            $this->audit->record($actor, 'aircraft_changed', $trip, $before, ['aircraft_id' => $trip->aircraft_id, 'aircraft_source' => $trip->aircraft_source, 'aircraft' => $trip->schedule_snapshot['aircraft_label']]);

            return $trip;
        });
    }
}
