<?php

namespace App\Http\Resources;

use App\Models\Assignment;
use App\Models\CrewMember;
use App\Models\Trip;
use App\Support\Roster\PlanningContext;
use App\Support\Roster\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full roster window for one week: the period, its trips with every seat, the crew rows for the grid
 * (with hours used against working hours and their activities) and the live conflicts.
 *
 * For crew accounts ($resource['crew_member_id'] set) everything is reduced to the caller's own seats,
 * activities and totals; peer names, decision logs and conflicts are never included.
 *
 * @property array{context: PlanningContext, conflicts: array<int, array<string, mixed>>, summary: array<string, int>, crew_member_id: ?int, route_changes?: array<string, mixed>|null} $resource
 */
class RosterWeekResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $context = $this->resource['context'];
        $own = $this->resource['crew_member_id'];
        $period = $context->period;
        $first = $period->starts_on->format('Y-m-d');
        $last = $period->ends_on->format('Y-m-d');
        $trips = $own === null ? $context->trips : $context->trips->filter(fn (Trip $trip): bool => $trip->assignments->contains('crew_member_id', $own));
        // Grid rows: active crew plus anyone (even inactive) holding a seat this week; crew accounts see only themselves.
        $seated = $context->trips->flatMap(fn (Trip $trip) => $trip->assignments->pluck('crew_member_id'))->filter()->flip();
        $crews = $own === null
            ? $context->crews->filter(fn (CrewMember $crew): bool => $crew->active || $seated->has($crew->id))
            : $context->crews->only([$own]);

        return [
            ...(new RosterPeriodResource($period))->toArray($request),
            'today' => $context->today,
            'rules' => $own === null ? collect($context->rules)->only(['min_rest_h', 'max_duty_7d_h', 'max_block_month_h', 'max_consecutive_days', 'min_days_off_month', 'utc_offset_minutes'])->all() : null,
            'utc_offset_minutes' => (int) $context->rules['utc_offset_minutes'],
            'summary' => $this->resource['summary'],
            'trips' => $trips->values()->map(fn (Trip $trip): array => $this->trip($context, $trip, $own))->all(),
            'crew' => $crews->sortBy(fn (CrewMember $crew): array => [array_search($crew->rank, ['CPT', 'FO', 'CC'], true), $crew->name, $crew->id])->values()
                ->map(fn (CrewMember $crew): array => [
                    'id' => $crew->id,
                    'name' => $crew->name,
                    'rank' => $crew->rank,
                    'base_airport' => $crew->base_airport,
                    'active' => $crew->active,
                    'weekly_hours' => $crew->weekly_hours,
                    // Totals for the whole period (one week, two weeks or a month).
                    'period_duty_minutes' => $context->schedule($crew->id)->minutesBetween($first, $last),
                    'period_block_minutes' => $context->schedule($crew->id)->blockMinutesBetween($first, $last),
                    'activities' => $crew->activities->filter(fn ($activity): bool => $activity->date->format('Y-m-d') >= $first && $activity->date->format('Y-m-d') <= $last)
                        ->sortBy('date')->values()->map(fn ($activity): array => [
                            'id' => $activity->id, 'date' => $activity->date->format('Y-m-d'), 'type' => $activity->type, 'generated' => $activity->roster_period_id !== null,
                            'starts_at' => $activity->starts_at?->toIso8601String(), 'ends_at' => $activity->ends_at?->toIso8601String(), 'note' => $activity->note,
                        ])->all(),
                ])->all(),
            'conflicts' => $own === null ? $this->resource['conflicts'] : [],
            // Staff only: what a rebuild would change to follow the current flight routes (null before the first
            // build and once the roster has ended). Crew never see unpublished planning changes.
            'route_changes' => $own === null ? $this->routeChanges($this->resource['route_changes'] ?? null) : null,
            // Staff only: crew whose duties changed at the latest publication [{crew_member_id, name, removed, added}].
            'changed_crew' => $own === null ? $period->changed_crew : null,
        ];
    }

    /**
     * A captain section's own route and duties (null for a seat covering the whole trip), so the grid and the
     * individual roster show the times that captain actually reports and is released.
     *
     * @param  array<string, mixed>  $snapshot  the trip's whole schedule snapshot
     * @return array{from_leg: int, to_leg: int, route: string, from_airport: ?string, to_airport: ?string, report: string, release: string, block_minutes: int, duty_minutes: int, duties: array<int, array<string, mixed>>}|null
     */
    private function section(array $snapshot, Assignment $seat): ?array
    {
        if (! Section::isPartial($snapshot, $seat->from_leg, $seat->to_leg)) {
            return null;
        }
        $section = Section::restrict($snapshot, $seat->from_leg, $seat->to_leg);

        return [
            'from_leg' => $seat->from_leg, 'to_leg' => $seat->to_leg, 'route' => $section['route'],
            'from_airport' => $section['section']['from_airport'], 'to_airport' => $section['section']['to_airport'],
            'report' => $section['report'], 'release' => $section['release'],
            'block_minutes' => $section['block_minutes'], 'duty_minutes' => $section['duty_minutes'], 'duties' => $section['duties'],
        ];
    }

    /**
     * The pending route changes in the shape the roster window's review dialog reads. The fingerprint is sent
     * back with the rebuild to confirm exactly this set.
     *
     * @param  array<string, mixed>|null  $changes  RosterScheduleService::changes() result
     * @return array<string, mixed>|null
     */
    private function routeChanges(?array $changes): ?array
    {
        if ($changes === null) {
            return null;
        }

        return [
            'count' => $changes['count'],
            'fingerprint' => $changes['fingerprint'],
            'released_seats' => $changes['released_seats'],
            'locked_seats' => $changes['locked_seats'],
            'removed' => $changes['removed'],
            'added' => $changes['added'],
            'changed' => $changes['changed'],
            'skipped' => $changes['skipped'],
        ];
    }

    /**
     * One trip with its schedule snapshot and seats (only the caller's own seat for crew accounts).
     *
     * @return array<string, mixed>
     */
    private function trip(PlanningContext $context, Trip $trip, ?int $own): array
    {
        $whole = $trip->schedule_snapshot;
        $seats = $trip->assignments->sortBy(fn (Assignment $seat): array => [array_search($seat->rank, ['CPT', 'FO', 'CC'], true), $seat->seat_number])->values();
        if ($own !== null) {
            $seats = $seats->where('crew_member_id', $own)->values();
        }
        // A captain who flies only a section of the trip sees just that section as their duty.
        $ownSeat = $own !== null ? $seats->first() : null;
        $snapshot = $ownSeat ? Section::restrict($whole, $ownSeat->from_leg, $ownSeat->to_leg) : $whole;

        return [
            'id' => $trip->id,
            'flight_id' => $trip->flight_id,
            'code' => $snapshot['code'] ?? $trip->flight->code,
            'start_date' => $trip->start_date->format('Y-m-d'),
            'operated' => $context->operated($trip),
            'flight_active' => $trip->flight->active,
            'aircraft_type' => $snapshot['aircraft_type'] ?? null,
            // The plane flying the trip ("7Q-TBA · Boeing 737-800 (B737)"), or the planned type when none is set;
            // aircraft_source "manual" means crew control chose it and rebuilds keep it.
            'aircraft_id' => $trip->aircraft_id,
            'registration' => $snapshot['registration'] ?? null,
            'aircraft_label' => $snapshot['aircraft_label'] ?? $snapshot['aircraft_type'] ?? null,
            'aircraft_source' => $own === null ? $trip->aircraft_source : null,
            'palette' => $snapshot['palette'] ?? null,
            'base' => $snapshot['base'] ?? null,
            'route' => $snapshot['route'] ?? null,
            'report' => $snapshot['report'] ?? null,
            'release' => $snapshot['release'] ?? null,
            'block_minutes' => $snapshot['block_minutes'] ?? 0,
            'duty_minutes' => $snapshot['duty_minutes'] ?? 0,
            'duties' => $snapshot['duties'] ?? [],
            'warnings' => $own === null ? $snapshot['warnings'] ?? [] : [],
            // Staff only: every leg in trip order (numbered from 1), for choosing where a captain changes.
            'legs' => $own === null ? array_map(fn (array $leg): array => ['number' => $leg['number'], 'from_airport' => $leg['from_airport'], 'to_airport' => $leg['to_airport'],
                'departs_local' => $leg['departs_local'], 'arrives_local' => $leg['arrives_local']], Section::legs($whole)) : [],
            // Crew excluded from this trip by crew control (staff only).
            'exclusions' => $own === null ? $trip->exclusions->map(fn ($exclusion): array => ['id' => $exclusion->id, 'crew_member_id' => $exclusion->crew_member_id, 'name' => $context->crews->get($exclusion->crew_member_id)?->name])->values()->all() : [],
            'assignments' => $seats->map(function (Assignment $seat) use ($context, $own, $whole): array {
                $crew = $seat->crew_member_id ? $context->crews->get($seat->crew_member_id) : null;

                return [
                    'id' => $seat->id,
                    'rank' => $seat->rank,
                    'seat_number' => $seat->seat_number,
                    'from_leg' => $seat->from_leg,
                    'to_leg' => $seat->to_leg,
                    'section' => $this->section($whole, $seat),
                    'source' => $seat->source,
                    'crew_member_id' => $seat->crew_member_id,
                    'crew' => $crew ? ['id' => $crew->id, 'name' => $crew->name, 'rank' => $crew->rank, 'base_airport' => $crew->base_airport] : null,
                    'flag_reasons' => $own === null ? $seat->flag_reasons ?? [] : [],
                    'decision_log' => $own === null ? $seat->decision_log : null,
                    'can_undo' => $own === null && isset($seat->decision_log['previous']),
                ];
            })->all(),
        ];
    }
}
