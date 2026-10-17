<?php

namespace App\Services;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Assignment;
use App\Models\Flight;
use App\Models\RosterPeriod;
use App\Models\RuleSet;
use App\Models\Trip;
use App\Support\Roster\Duty;
use App\Support\Roster\Section;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The flight schedule a roster should follow, and how far the roster has drifted from it.
 *
 * plan() expands every enabled flight pattern into the dated trips a roster needs from today onwards (with the
 * UTC schedule snapshot each trip keeps). RosterBuilderService applies that plan on every build; changes()
 * compares it with the trips already on a built roster, so crew control can see what a rebuild would do after
 * someone disables, enables or edits a flight on the Flight routes page:
 *
 *  - removed: trips whose flight was disabled, no longer operates on that day or no longer passes the roster's
 *    frozen duty rules (any crew on them, locked seats included, are released);
 *  - added: trips an enabled flight now needs that the roster does not have yet;
 *  - changed: trips whose flight number, route, times, plane or aircraft type, or cabin seats differ from the
 *    pattern (a plane swap, e.g. when the planned airframe went into maintenance, shows here too).
 *
 * Trips before today are history and never compared. A rebuild that would apply changes must quote the
 * fingerprint of the exact set the scheduler reviewed (see RosterBuilderService::build()).
 */
class RosterScheduleService
{
    /** Why a trip leaves the roster, worded for the review dialog. */
    public const REMOVAL_REASONS = [
        'disabled' => 'Flight disabled on Flight routes',
        'not_operating' => 'Flight no longer operates on this day',
        'invalid' => 'Flight no longer passes this roster\'s duty rules',
    ];

    public function __construct(private ExpiryService $expiry, private FlightTimelineService $timeline) {}

    /**
     * The trips the enabled flight patterns call for in a roster from today onwards, keyed "flight_id|date".
     * Patterns that fail the roster's frozen duty rules are left out and reported, so a rules change after the
     * roster was created never plans an illegal trip. "skipped" holds "LB1: reason" messages for the build
     * result; "invalid" holds the same reasons keyed by flight id. Each trip also gets its airframe (see
     * assignAircraft()), and its snapshot follows that plane's type.
     *
     * @return array{trips: array<string, array{flight: Flight, date: string, snapshot: array<string, mixed>, aircraft_id: ?int, aircraft_source: string}>, skipped: array<int, string>, invalid: array<int, string>}
     */
    public function plan(RosterPeriod $period, ?CarbonImmutable $today = null): array
    {
        $today ??= $this->expiry->today();
        $rules = new RuleSet($period->rules_snapshot);
        $offset = (int) $period->rules_snapshot['utc_offset_minutes'];
        $trips = [];
        $skipped = [];
        $invalid = [];
        foreach (Flight::query()->where('active', true)->with(['legs', 'days', 'aircraftType', 'aircraft'])->orderBy('code')->get() as $flight) {
            if ($flight->legs->isEmpty()) {
                continue;
            }
            try {
                $timeline = $this->timeline->validate($flight->legs->map(fn ($leg): array => $leg->only(['trip_day', 'from_airport', 'to_airport', 'departs_local', 'arrives_local']))->all(), $rules);
            } catch (ValidationException $exception) {
                $invalid[$flight->id] = (string) collect($exception->errors())->flatten()->first();
                $skipped[] = $flight->code.': '.$invalid[$flight->id];

                continue;
            }
            // Every date of the period (a week, two weeks or a month) on which the pattern operates (Monday = 0).
            $weekdays = $flight->days->pluck('weekday')->all();
            foreach ($period->dates() as $day) {
                $date = CarbonImmutable::parse($day, 'UTC');
                if ($date->lt($today) || ! in_array($date->dayOfWeekIso - 1, $weekdays, true)) {
                    continue;
                }
                $trips[$flight->id.'|'.$day] = ['flight' => $flight, 'date' => $day, 'snapshot' => $this->snapshot($flight, $timeline, $date, $offset)];
            }
        }
        $this->assignAircraft($period, $today, $trips);

        return ['trips' => $trips, 'skipped' => $skipped, 'invalid' => $invalid];
    }

    /**
     * Give each planned trip its airframe, earliest trips first:
     *  - a plane crew control set by hand (aircraft_source "manual") is kept, whatever its state;
     *  - otherwise, from the flight plan's planes in order of preference, the trip keeps the plane it had when
     *    that one is still available and free, or takes the first available plane not already flying another
     *    trip at the same time (in this roster, its operated trips or other rosters);
     *  - when none is free it keeps its plane (or takes the first listed) and the conflict check warns;
     *  - a flight plan without planes keeps planning by aircraft type only.
     * The trip's snapshot then follows the chosen plane's type (cabin seats, ratings, palette).
     *
     * @param  array<string, array<string, mixed>>  $trips  plan() trips, updated in place
     */
    private function assignAircraft(RosterPeriod $period, CarbonImmutable $today, array &$trips): void
    {
        $existing = $period->trips()->get(['id', 'flight_id', 'start_date', 'aircraft_id', 'aircraft_source'])
            ->keyBy(fn (Trip $trip): string => $trip->flight_id.'|'.$trip->start_date->format('Y-m-d'));
        $fleet = Aircraft::query()->with('aircraftType')->get()->keyBy('id');
        $busy = $this->aircraftBusy($period, $today);
        // Earliest report first, so a plane goes to the trip that needs it first; the order is restored after.
        $order = array_keys($trips);
        uasort($trips, fn (array $a, array $b): int => [$a['snapshot']['report'], $a['snapshot']['code']] <=> [$b['snapshot']['report'], $b['snapshot']['code']]);
        foreach ($trips as $key => &$wanted) {
            $current = $existing->get($key);
            $start = Duty::minutes($wanted['snapshot']['report']);
            $end = Duty::minutes($wanted['snapshot']['release']);
            $free = fn (?Aircraft $plane): bool => $plane !== null && $plane->status === 'available'
                && ! collect($busy[$plane->id] ?? [])->contains(fn (array $window): bool => $window[0] < $end && $start < $window[1]);
            $plane = null;
            $source = 'auto';
            if ($current?->aircraft_source === 'manual' && $fleet->has($current->aircraft_id)) {
                $plane = $fleet->get($current->aircraft_id);
                $source = 'manual';
            } elseif ($wanted['flight']->aircraft->isNotEmpty()) {
                $listed = $wanted['flight']->aircraft->map(fn (Aircraft $item): ?Aircraft => $fleet->get($item->id))->filter()->values();
                $previous = $current?->aircraft_id ? $listed->firstWhere('id', $current->aircraft_id) : null;
                $plane = ($free($previous) ? $previous : null) ?? $listed->first($free) ?? $previous ?? $listed->first();
            }
            if ($plane !== null) {
                $busy[$plane->id][] = [$start, $end];
            }
            $wanted['snapshot'] = [...$wanted['snapshot'], ...$this->aircraftFields($plane, $wanted['flight']->aircraftType)];
            $wanted['aircraft_id'] = $plane?->id;
            $wanted['aircraft_source'] = $source;
        }
        unset($wanted);
        $trips = array_replace(array_flip($order), $trips);
    }

    /**
     * Times when planes are already flying trips that this plan does not decide: other rosters' trips around
     * the period and this roster's trips that have already operated.
     *
     * @return array<int, array<int, array{0: int, 1: int}>> aircraft id => [report, release] in epoch minutes
     */
    public function aircraftBusy(RosterPeriod $period, CarbonImmutable $today): array
    {
        $from = $period->starts_on->subDays((int) config('roster.max_trip_days'))->format('Y-m-d');
        $busy = [];
        $trips = Trip::query()->whereNotNull('aircraft_id')->whereBetween('start_date', [$from, $period->ends_on->addDay()->format('Y-m-d')])
            ->where(fn ($query) => $query->where('roster_period_id', '!=', $period->id)->orWhere('start_date', '<', $today->format('Y-m-d')))
            ->get(['id', 'aircraft_id', 'schedule_snapshot']);
        foreach ($trips as $trip) {
            if (isset($trip->schedule_snapshot['report'], $trip->schedule_snapshot['release'])) {
                $busy[$trip->aircraft_id][] = [Duty::minutes($trip->schedule_snapshot['report']), Duty::minutes($trip->schedule_snapshot['release'])];
            }
        }

        return $busy;
    }

    /**
     * The aircraft part of a trip snapshot: the plane (if any) and the type the trip follows, which is the
     * plane's own type, or the flight plan's planned type when no plane is assigned. aircraft_label is how the
     * plane is shown everywhere ("7Q-TBA · Boeing 737-800 (B737)").
     *
     * @return array{aircraft_type_id: ?int, aircraft_type: ?string, aircraft_name: ?string, palette: ?string, cabin_crew_required: int, aircraft_id: ?int, registration: ?string, aircraft_label: ?string}
     */
    public function aircraftFields(?Aircraft $plane, ?AircraftType $plannedType): array
    {
        $type = $plane?->aircraftType ?? $plannedType;

        return [
            'aircraft_type_id' => $type?->id,
            'aircraft_type' => $type?->code,
            'aircraft_name' => $type?->name,
            'palette' => $type?->palette,
            'cabin_crew_required' => (int) ($type?->cabin_crew_required ?? 0),
            'aircraft_id' => $plane?->id,
            'registration' => $plane?->registration,
            'aircraft_label' => $plane !== null ? $plane->label() : ($type?->name ? $type->name.' ('.$type->code.')' : $type?->code),
        ];
    }

    /**
     * What a rebuild would change on a built roster to match the current flight routes. Returns null for a
     * roster that has not been built (the first build plans everything) or has ended (history never changes).
     * Published rosters are compared too, so crew control knows to reopen and rebuild them.
     *
     * @param  array{trips: array<string, array<string, mixed>>, skipped: array<int, string>, invalid: array<int, string>}|null  $plan  plan() result when the caller already has it
     * @return array{count: int, fingerprint: string, released_seats: int, locked_seats: int,
     *               removed: array<int, array{trip_id: int, flight_id: int, code: string, date: string, route: ?string, reason: string, reason_label: string, seats: array<int, array{rank: string, seat_number: int, name: string, locked: bool}>}>,
     *               added: array<int, array{flight_id: int, code: string, date: string, route: string}>,
     *               changed: array<int, array{trip_id: int, flight_id: int, code: string, date: string, route: string, fields: array<int, string>, details: array<int, string>}>,
     *               skipped: array<int, string>}|null
     */
    public function changes(RosterPeriod $period, ?CarbonImmutable $today = null, ?array $plan = null): ?array
    {
        $today ??= $this->expiry->today();
        if ($period->built_at === null || $period->ends_on->lt($today)) {
            return null;
        }
        $plan ??= $this->plan($period, $today);
        $existing = $period->trips()->with(['flight:id,code,active', 'assignments.crewMember:id,name'])->get()
            ->filter(fn (Trip $trip): bool => $trip->start_date->gte($today))
            ->sortBy(fn (Trip $trip): array => [$trip->start_date->format('Y-m-d'), $trip->schedule_snapshot['code'] ?? '', $trip->id])
            ->keyBy(fn (Trip $trip): string => $trip->flight_id.'|'.$trip->start_date->format('Y-m-d'));

        $removed = [];
        $changed = [];
        foreach ($existing as $key => $trip) {
            $wanted = $plan['trips'][$key] ?? null;
            if ($wanted === null) {
                $reason = match (true) {
                    ! $trip->flight->active => 'disabled',
                    isset($plan['invalid'][$trip->flight_id]) => 'invalid',
                    default => 'not_operating',
                };
                $removed[] = [
                    'trip_id' => $trip->id, 'flight_id' => $trip->flight_id, 'code' => $trip->schedule_snapshot['code'] ?? $trip->flight->code,
                    'date' => $trip->start_date->format('Y-m-d'), 'route' => $trip->schedule_snapshot['route'] ?? null,
                    'reason' => $reason, 'reason_label' => self::REMOVAL_REASONS[$reason],
                    'seats' => $this->heldSeats($trip),
                ];

                continue;
            }
            $difference = $this->difference($trip, $wanted['snapshot']);
            if ($difference['fields'] !== []) {
                $changed[] = ['trip_id' => $trip->id, 'flight_id' => $trip->flight_id, 'code' => $wanted['snapshot']['code'], 'date' => $wanted['date'], 'route' => $wanted['snapshot']['route'], ...$difference];
            }
        }
        $added = [];
        foreach ($plan['trips'] as $key => $wanted) {
            if (! $existing->has($key)) {
                $added[] = ['flight_id' => $wanted['flight']->id, 'code' => $wanted['snapshot']['code'], 'date' => $wanted['date'], 'route' => $wanted['snapshot']['route']];
            }
        }
        usort($added, fn (array $a, array $b): int => [$a['date'], $a['code']] <=> [$b['date'], $b['code']]);
        $seats = collect($removed)->flatMap(fn (array $trip): array => $trip['seats']);

        return [
            'count' => count($removed) + count($added) + count($changed),
            'fingerprint' => $this->fingerprint($removed, $added, $changed),
            'released_seats' => $seats->count(),
            'locked_seats' => $seats->where('locked', true)->count(),
            'removed' => $removed,
            'added' => $added,
            'changed' => $changed,
            'skipped' => $plan['skipped'],
        ];
    }

    /**
     * Built rosters that have not ended and whose pending route changes involve a flight, oldest first. Used to
     * tell crew control which rosters to review after disabling, enabling or editing that flight.
     *
     * @return Collection<int, RosterPeriod>
     */
    public function periodsAffectedBy(Flight $flight): Collection
    {
        $today = $this->expiry->today();

        return RosterPeriod::query()->whereNotNull('built_at')->where('ends_on', '>=', $today->format('Y-m-d'))->orderBy('starts_on')->get()
            ->filter(function (RosterPeriod $period) use ($flight, $today): bool {
                $changes = $this->changes($period, $today);

                return $changes !== null && collect([...$changes['removed'], ...$changes['added'], ...$changes['changed']])->contains('flight_id', $flight->id);
            })->values();
    }

    /**
     * Turn a validated flight pattern into a dated schedule snapshot: each duty period's report and release,
     * and each leg's times, as UTC instants plus their base-local clock times and dates. The offset comes
     * from the roster's rules snapshot, never from the server or browser timezone.
     *
     * @param  array{base: string, periods: array<int, array<string, mixed>>, warnings: array<int, string>}  $timeline  FlightTimelineService::validate() result
     * @return array<string, mixed>
     */
    public function snapshot(Flight $flight, array $timeline, CarbonImmutable $date, int $offset): array
    {
        $day = $date->format('Y-m-d');
        $instant = fn (int $minutes): string => $this->timeline->instant($day, $minutes, $offset);
        $localDate = fn (int $minutes): string => $date->addDays((int) floor($minutes / 1440))->format('Y-m-d');
        $clock = fn (int $minutes): string => sprintf('%02d:%02d', intdiv((($minutes % 1440) + 1440) % 1440, 60), (($minutes % 60) + 60) % 60);
        $duties = [];
        $route = [$timeline['base']];
        foreach ($timeline['periods'] as $period) {
            $dates = [];
            for ($at = $date->addDays((int) floor($period['report_min'] / 1440)); $at->format('Y-m-d') <= $localDate($period['release_min'] - 1); $at = $at->addDay()) {
                $dates[] = $at->format('Y-m-d');
            }
            $legs = [];
            foreach ($period['legs'] as $leg) {
                $route[] = $leg['to_airport'];
                $legs[] = [
                    'from_airport' => $leg['from_airport'], 'to_airport' => $leg['to_airport'],
                    'departs' => $instant($leg['departure_min']), 'arrives' => $instant($leg['arrival_min']),
                    'departs_local' => $clock($leg['departure_min']), 'arrives_local' => $clock($leg['arrival_min']),
                    'block_minutes' => $leg['arrival_min'] - $leg['departure_min'],
                ];
            }
            $duties[] = [
                'trip_day' => $period['trip_day'],
                'date' => $localDate($period['report_min']),
                'dates' => $dates,
                'report' => $instant($period['report_min']), 'release' => $instant($period['release_min']),
                'report_local' => $clock($period['report_min']), 'release_local' => $clock($period['release_min']),
                'block_minutes' => $period['block_min'],
                'duty_minutes' => $period['release_min'] - $period['report_min'],
                'legs' => $legs,
            ];
        }

        return [
            'code' => $flight->code,
            'flight_id' => $flight->id,
            // The planned type; plan() replaces these with the trip's own plane when one is assigned.
            ...$this->aircraftFields(null, $flight->aircraftType),
            'base' => $timeline['base'],
            'route' => implode('-', $route),
            'utc_offset_minutes' => $offset,
            'report' => $duties[0]['report'],
            'release' => $duties[array_key_last($duties)]['release'],
            'block_minutes' => array_sum(array_column($duties, 'block_minutes')),
            'duty_minutes' => array_sum(array_column($duties, 'duty_minutes')),
            'duties' => $duties,
            'warnings' => $timeline['warnings'],
        ];
    }

    /**
     * How a planned trip differs from the pattern it should now follow. Palette and rule warnings are ignored:
     * a rebuild refreshes them silently and they do not change anyone's duty.
     *
     * @param  array<string, mixed>  $snapshot  the snapshot plan() would give the trip now
     * @return array{fields: array<int, string>, details: array<int, string>} fields: code|route|sections|times|aircraft|seats
     */
    private function difference(Trip $trip, array $snapshot): array
    {
        $old = $trip->schedule_snapshot ?? [];
        $fields = [];
        $details = [];
        if (($old['code'] ?? null) !== $snapshot['code']) {
            $fields[] = 'code';
            $details[] = 'Flight number '.$snapshot['code'].' (was '.($old['code'] ?? '—').')';
        }
        if (($old['route'] ?? null) !== $snapshot['route']) {
            $fields[] = 'route';
            $details[] = 'Route '.$snapshot['route'].' (was '.($old['route'] ?? '—').')';
        }
        // Captain sections are leg ranges: if the legs change, the rebuild joins them back into one captain seat.
        $sections = $trip->assignments->where('rank', 'CPT');
        if (($sections->count() > 1 || $sections->contains(fn (Assignment $seat): bool => $seat->from_leg !== null)) && Section::signature($old) !== Section::signature($snapshot)) {
            $fields[] = 'sections';
            $released = $sections->whereNotNull('crew_member_id')->map(fn (Assignment $seat): string => ($seat->crewMember?->name ?? 'Unknown crew member').' ('.Section::route($old, $seat->from_leg, $seat->to_leg).')')->values()->all();
            $details[] = 'Captain sections joined back into one seat'.($released === [] ? '' : '; releases '.implode(', ', $released));
        }
        if ($this->times($old) !== $this->times($snapshot)) {
            $fields[] = 'times';
            $details[] = 'Duty '.$this->dutyClock($snapshot).' LT (was '.$this->dutyClock($old).')';
        }
        // Another plane (or type): crew requirements may change with it, so the review shows both.
        $oldPlane = isset($old['aircraft_id']) ? (int) $old['aircraft_id'] : null;
        if ((int) ($old['aircraft_type_id'] ?? 0) !== (int) $snapshot['aircraft_type_id'] || $oldPlane !== $snapshot['aircraft_id']) {
            $fields[] = 'aircraft';
            $details[] = 'Aircraft '.($snapshot['aircraft_label'] ?? $snapshot['aircraft_type']).' (was '.($old['aircraft_label'] ?? $old['aircraft_type'] ?? '—').')';
        }
        // The cabin requirement follows the aircraft type: seats are added, or surplus ones dropped unless locked.
        $cabin = $trip->assignments->where('rank', 'CC');
        $required = (int) $snapshot['cabin_crew_required'];
        $missing = $cabin->filter(fn (Assignment $seat): bool => $seat->seat_number <= $required)->count() < $required;
        $surplus = $cabin->contains(fn (Assignment $seat): bool => $seat->seat_number > $required && ! ($seat->source === 'manual' && $seat->crew_member_id !== null));
        if ($missing || $surplus) {
            $fields[] = 'seats';
            $details[] = $required.' cabin '.($required === 1 ? 'seat' : 'seats').' (was '.$cabin->count().')';
        }

        return ['fields' => $fields, 'details' => $details];
    }

    /**
     * The instants that decide a trip's duties: each duty's report and release and each leg's departure and
     * arrival. Compared as UTC strings, which the snapshot always stores in the same format.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array<int, mixed>>
     */
    private function times(array $snapshot): array
    {
        return array_map(fn (array $duty): array => [$duty['report'] ?? null, $duty['release'] ?? null, array_map(fn (array $leg): array => [$leg['departs'] ?? null, $leg['arrives'] ?? null], $duty['legs'] ?? [])], $snapshot['duties'] ?? []);
    }

    /**
     * "07:00–11:10" from the first report to the last release of a snapshot, in base-local time.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function dutyClock(array $snapshot): string
    {
        $duties = $snapshot['duties'] ?? [];
        if ($duties === []) {
            return '—';
        }

        return ($duties[0]['report_local'] ?? '—').'–'.($duties[array_key_last($duties)]['release_local'] ?? '—');
    }

    /**
     * The crew a removed trip would release, so the review names everyone whose duty disappears.
     *
     * @return array<int, array{rank: string, seat_number: int, name: string, locked: bool, section: ?string}>
     */
    private function heldSeats(Trip $trip): array
    {
        return $trip->assignments->whereNotNull('crew_member_id')
            ->sortBy(fn (Assignment $seat): array => [array_search($seat->rank, ['CPT', 'FO', 'CC'], true), $seat->seat_number])
            ->map(fn (Assignment $seat): array => ['rank' => $seat->rank, 'seat_number' => $seat->seat_number, 'name' => $seat->crewMember?->name ?? 'Unknown crew member', 'locked' => $seat->source === 'manual',
                // A captain who flies only part of the trip: the airports of their section.
                'section' => Section::isPartial($trip->schedule_snapshot ?? [], $seat->from_leg, $seat->to_leg) ? Section::route($trip->schedule_snapshot, $seat->from_leg, $seat->to_leg) : null])
            ->values()->all();
    }

    /**
     * A short hash of exactly what a rebuild would change, including who is released. If routes or seats move
     * again after the scheduler reviewed them, the hash changes and the rebuild asks for a fresh review.
     *
     * @param  array<int, array<string, mixed>>  $removed
     * @param  array<int, array<string, mixed>>  $added
     * @param  array<int, array<string, mixed>>  $changed
     */
    private function fingerprint(array $removed, array $added, array $changed): string
    {
        return sha1(json_encode([
            array_map(fn (array $trip): array => [$trip['trip_id'], $trip['reason'], array_map(fn (array $seat): array => [$seat['rank'], $seat['seat_number'], $seat['name'], $seat['locked']], $trip['seats'])], $removed),
            array_map(fn (array $trip): array => [$trip['flight_id'], $trip['date']], $added),
            array_map(fn (array $trip): array => [$trip['trip_id'], $trip['details']], $changed),
        ], JSON_THROW_ON_ERROR));
    }
}
