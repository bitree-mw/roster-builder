<?php

namespace App\Services;

use App\Models\RosterPeriod;
use App\Support\Roster\PlanningContext;

/**
 * Finds everything wrong with a roster week as it stands now, re-checking every filled seat against the
 * current crew data (leave, documents, ratings, other duties). Seats can become illegal after the build,
 * for example when leave is added later, so conflicts are always calculated live, never stored.
 *
 * Severity "danger" is a broken rule on a filled seat; it blocks publishing unless a scheduler accepted
 * exactly that problem with a recorded override. Severity "warning" (open seat, disabled flight, no
 * serviceable airframe) is shown but does not block publishing.
 */
class RosterConflictService
{
    public function __construct(private RosterLegalityService $legality) {}

    /**
     * All conflicts for a week, ordered by trip date then seat.
     *
     * @return array<int, array{severity: string, code: string, message: string, blocking: bool, acknowledged: bool, trip_id: int, assignment_id: ?int, crew_member_id: ?int, crew_name: ?string, rank: ?string, flight_code: string, date: string}>
     */
    public function forPeriod(RosterPeriod $period, ?PlanningContext $context = null): array
    {
        $context ??= $this->legality->context($period);
        $conflicts = [];
        foreach ($context->trips as $trip) {
            $snapshot = $trip->schedule_snapshot;
            $base = ['trip_id' => $trip->id, 'flight_code' => $snapshot['code'] ?? $trip->flight->code, 'date' => $trip->start_date->format('Y-m-d'), 'assignment_id' => null, 'crew_member_id' => null, 'crew_name' => null, 'rank' => null];
            $warning = fn (string $code, string $message, array $extra = []): array => [...$base, ...$extra, 'severity' => 'warning', 'code' => $code, 'message' => $message, 'blocking' => false, 'acknowledged' => false];
            if (! $trip->flight->active) {
                $conflicts[] = $warning('flight_disabled', 'Flight '.$base['flight_code'].' is disabled but this trip is still on the roster.');
            }
            $fleet = $context->airframes[$snapshot['aircraft_type_id'] ?? 0] ?? null;
            if ($fleet !== null && $fleet['total'] > 0 && $fleet['available'] === 0) {
                $conflicts[] = $warning('no_aircraft', 'No serviceable '.($snapshot['aircraft_type'] ?? 'aircraft').' airframe: all '.$fleet['total'].' are unavailable.');
            }
            foreach ($trip->assignments->sortBy(fn ($seat): array => [array_search($seat->rank, ['CPT', 'FO', 'CC'], true), $seat->seat_number]) as $seat) {
                $seatBase = ['assignment_id' => $seat->id, 'rank' => $seat->rank];
                if ($seat->crew_member_id === null) {
                    $conflicts[] = $warning('open_seat', 'Open '.RosterLegalityService::RANKS[$seat->rank].' seat.', $seatBase);

                    continue;
                }
                $crew = $context->crews->get($seat->crew_member_id);
                foreach ($this->legality->issues($context, $crew, $trip, $seat) as $issue) {
                    $acknowledged = in_array($issue['message'], $seat->flag_reasons ?? [], true);
                    $conflicts[] = [...$base, ...$seatBase, 'crew_member_id' => $crew->id, 'crew_name' => $crew->name, 'severity' => 'danger', 'code' => $issue['code'], 'message' => $issue['message'], 'blocking' => ! $acknowledged, 'acknowledged' => $acknowledged];
                }
                // Missing document records under the "warn" policy: shown, never blocking.
                foreach ($this->legality->documentWarnings($context, $crew) as $message) {
                    $conflicts[] = $warning('document_missing', $message, [...$seatBase, 'crew_member_id' => $crew->id, 'crew_name' => $crew->name]);
                }
            }
        }

        return $conflicts;
    }

    /**
     * Seat and conflict counts for a week's KPI strip and the dashboard.
     *
     * @param  array<int, array<string, mixed>>  $conflicts  from forPeriod()
     * @return array{trips: int, seats: int, filled: int, open: int, conflicts: int, blocking: int, acknowledged: int, warnings: int}
     */
    public function summary(PlanningContext $context, array $conflicts): array
    {
        $seats = $context->trips->sum(fn ($trip): int => $trip->assignments->count());
        $filled = $context->trips->sum(fn ($trip): int => $trip->assignments->whereNotNull('crew_member_id')->count());
        $danger = collect($conflicts)->where('severity', 'danger');

        return [
            'trips' => $context->trips->count(),
            'seats' => $seats,
            'filled' => $filled,
            'open' => $seats - $filled,
            'conflicts' => $danger->count(),
            'blocking' => $danger->where('blocking', true)->count(),
            'acknowledged' => $danger->where('acknowledged', true)->count(),
            'warnings' => collect($conflicts)->where('severity', 'warning')->where('code', '!=', 'open_seat')->count(),
        ];
    }
}
