<?php

namespace App\Services;

use App\Models\Flight;
use App\Models\RuleSet;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business operations for flight patterns: saving (with duty/rest validation), enabling/disabling and deletion.
 */
class FlightService
{
    public function __construct(private AuditService $audit, private FlightTimelineService $timeline) {}

    /**
     * Validate the leg timeline against the current duty rules, then create or replace the pattern, its
     * operating days and its legs in one transaction with an audit entry.
     *
     * @param  array<string, mixed>  $data  validated FlightRequest payload (weekdays and ordered legs)
     */
    public function save(array $data, User $actor, ?Flight $flight = null): Flight
    {
        return DB::transaction(function () use ($data, $actor, $flight): Flight {
            $this->timeline->validate($data['legs'], RuleSet::query()->findOrFail(1));
            $flight ??= new Flight;
            $before = $flight->exists ? $flight->load('legs', 'days')->toArray() : null;
            $flight->fill(Arr::except($data, ['weekdays', 'legs']))->save();
            // Days and legs are fully replaced from the payload; leg sequence is assigned here, never by the client.
            $flight->days()->delete();
            $flight->days()->createMany(array_map(fn (int $day): array => ['weekday' => $day], $data['weekdays']));
            $flight->legs()->delete();
            $sequences = [];
            foreach ($data['legs'] as $leg) {
                $day = $leg['trip_day'];
                $sequences[$day] = ($sequences[$day] ?? 0) + 1;
                $flight->legs()->create([...$leg, 'sequence' => $sequences[$day]]);
            }
            $flight->load('aircraftType', 'legs', 'days');
            $this->audit->record($actor, $before ? 'updated' : 'created', $flight, $before, $flight->toArray());

            return $flight;
        });
    }

    /** Enable or disable a flight pattern without resubmitting its legs; disabled patterns are not expanded into trips. */
    public function setActive(Flight $flight, bool $active, User $actor): Flight
    {
        return DB::transaction(function () use ($flight, $active, $actor): Flight {
            $flight = Flight::query()->lockForUpdate()->findOrFail($flight->id);
            $before = $flight->toArray();
            $flight->active = $active;
            $flight->save();
            $this->audit->record($actor, $active ? 'enabled' : 'disabled', $flight, $before, $flight->toArray());

            return $flight;
        });
    }

    /**
     * Delete a pattern that has never been rostered. Patterns with trips must be disabled instead.
     *
     * @throws ValidationException when trip history exists
     */
    public function delete(Flight $flight, User $actor): void
    {
        DB::transaction(function () use ($flight, $actor): void {
            if ($flight->trips()->exists()) {
                throw ValidationException::withMessages(['flight' => 'This flight has roster history. Mark it inactive instead.']);
            }
            $this->audit->record($actor, 'deleted', $flight, $flight->load('legs', 'days')->toArray());
            $flight->delete();
        });
    }
}
