<?php

namespace Tests\Feature\Api;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Assignment;
use App\Models\Flight;
use App\Models\Trip;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Planes on flight plans and trips: a plan lists the airframes that can fly it (any type, in order of
 * preference); each dated trip gets the first available plane not already flying at that time; a plane that
 * becomes unavailable is swapped through the reviewed rebuild or kept with a warning when none is free; a trip
 * follows its plane's type for cabin seats and pilot ratings; and crew control can put any plane on a trip.
 */
class TripAircraftTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    private AircraftType $b737;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        $this->q400->update(['name' => 'De Havilland Dash 8-400']);
        $this->b737 = AircraftType::factory()->create(['code' => 'B737', 'name' => 'Boeing 737-800', 'cabin_crew_required' => 3, 'palette' => 'gold']);
    }

    public function test_overlapping_trips_get_different_listed_planes_in_order_of_preference(): void
    {
        $alpha = $this->plane('7Q-TBA');
        $bravo = $this->plane('7Q-TBB');
        $this->listPlanes($this->flight('LB1', [0]), [$alpha, $bravo]);
        $this->listPlanes($this->flight('LB2', [0], '08:30'), [$alpha, $bravo]);
        $period = $this->week();
        $this->actingAsScheduler();

        $trips = collect($this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()->json('data.trips'))->keyBy('code');

        $this->assertSame(['7Q-TBA', '7Q-TBA · De Havilland Dash 8-400 (Q400)'], [$trips['LB1']['registration'], $trips['LB1']['aircraft_label']]);
        $this->assertSame('7Q-TBB', $trips['LB2']['registration']);
        $this->assertDatabaseHas('trips', ['flight_id' => Flight::firstWhere('code', 'LB2')->id, 'aircraft_id' => $bravo->id, 'aircraft_source' => 'auto']);
    }

    public function test_an_unavailable_plane_is_swapped_after_review_and_kept_with_a_warning_when_none_is_free(): void
    {
        $alpha = $this->plane('7Q-TBA');
        $bravo = $this->plane('7Q-TBB');
        $this->listPlanes($this->flight('LB1', [0]), [$alpha, $bravo]);
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()->assertJsonPath('data.trips.0.registration', '7Q-TBA');
        $alpha->forceFill(['status' => 'maintenance'])->save();

        $changes = $this->getJson("/api/v1/roster-periods/{$period->id}")->assertJsonPath('data.route_changes.changed.0.fields', ['aircraft'])->json('data.route_changes');
        $this->assertSame(['Aircraft 7Q-TBB · De Havilland Dash 8-400 (Q400) (was 7Q-TBA · De Havilland Dash 8-400 (Q400))'], $changes['changed'][0]['details']);
        $this->postJson("/api/v1/roster-periods/{$period->id}/build", ['route_changes' => $changes['fingerprint']])->assertOk()->assertJsonPath('data.trips.0.registration', '7Q-TBB');

        $bravo->forceFill(['status' => 'grounded'])->save();

        $week = $this->getJson("/api/v1/roster-periods/{$period->id}")->assertOk()->assertJsonPath('data.route_changes.count', 0)->assertJsonPath('data.summary.blocking', 0);
        $this->assertSame(['7Q-TBB is not available (AOG). Choose another plane, or rebuild to swap it for a free one.'], collect($week->json('data.conflicts'))->where('code', 'aircraft_unavailable')->pluck('message')->all());
    }

    public function test_a_plane_already_flying_at_the_same_time_is_a_double_booking_warning(): void
    {
        $alpha = $this->plane('7Q-TBA');
        $this->listPlanes($this->flight('LB1', [0]), [$alpha]);
        $this->listPlanes($this->flight('LB2', [0], '08:30'), [$alpha]);
        $period = $this->week();
        $this->actingAsScheduler();

        $conflicts = collect($this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()->json('data.conflicts'))->where('code', 'aircraft_double_booked');

        $this->assertSame(['7Q-TBA is also flying LB2 on 05 Oct at the same time.', '7Q-TBA is also flying LB1 on 05 Oct at the same time.'], $conflicts->pluck('message')->values()->all());
        $this->assertFalse($conflicts->contains('blocking', true));
    }

    public function test_a_trip_follows_its_planes_type_for_cabin_seats_and_pilot_ratings(): void
    {
        $this->listPlanes($this->flight('LB1', [0]), [$this->plane('7Q-TBC', $this->b737)]);
        $this->crew('CPT', 'Alpha Captain');
        $this->crew('FO', 'Charlie Officer');
        foreach (['Delta', 'Echo', 'Foxtrot'] as $name) {
            $this->crew('CC', $name.' Cabin');
        }
        $period = $this->week();
        $this->actingAsScheduler();

        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()
            ->assertJsonPath('data.trips.0.aircraft_type', 'B737')->assertJsonPath('data.summary.seats', 5)->assertJsonPath('data.summary.filled', 3);

        $captainSeat = Assignment::firstWhere('rank', 'CPT');
        $this->assertNull($captainSeat->crew_member_id);
        $this->assertSame(['rating' => 1], $captainSeat->decision_log['reason_counts']);
    }

    public function test_crew_control_puts_any_plane_on_a_trip_and_rebuilds_keep_it(): void
    {
        $alpha = $this->plane('7Q-TBA');
        $jet = $this->plane('7Q-TBC', $this->b737);
        $this->listPlanes($this->flight('LB1', [0]), [$alpha]);
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()->assertJsonPath('data.summary.seats', 3);
        $trip = Trip::query()->sole();

        $options = $this->getJson("/api/v1/trips/{$trip->id}/aircraft-options")->assertOk()->json('data');
        $this->assertSame([['7Q-TBA', true, true], ['7Q-TBC', false, false]], array_map(fn (array $option): array => [$option['registration'], $option['listed'], $option['current']], $options));
        $this->putJson("/api/v1/trips/{$trip->id}/aircraft", ['aircraft_id' => $jet->id])->assertOk()
            ->assertJsonPath('message', 'LB1 on 05 Oct will be flown by 7Q-TBC · Boeing 737-800 (B737) (needs 3 cabin crew). The plane is kept on rebuilds.');

        $this->assertDatabaseHas('trips', ['id' => $trip->id, 'aircraft_id' => $jet->id, 'aircraft_source' => 'manual']);
        $this->assertSame(5, Assignment::query()->where('trip_id', $trip->id)->count());
        $this->assertDatabaseHas('audit_logs', ['entity' => 'trips', 'entity_id' => $trip->id, 'action' => 'aircraft_changed']);
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()->assertJsonPath('data.trips.0.registration', '7Q-TBC')->assertJsonPath('data.trips.0.aircraft_source', 'manual');
        $this->putJson("/api/v1/trips/{$trip->id}/aircraft", [])->assertUnprocessable()->assertJsonValidationErrors('aircraft_id');
    }

    public function test_flight_plans_save_planes_in_order_and_show_them_with_the_type_name(): void
    {
        $alpha = $this->plane('7Q-TBA');
        $jet = $this->plane('7Q-TBC', $this->b737);
        $this->actingAsScheduler();
        $payload = ['code' => 'LB9', 'aircraft_type_id' => $this->q400->id, 'active' => true, 'weekdays' => [0], 'aircraft_ids' => [$jet->id, $alpha->id], 'legs' => [
            ['trip_day' => 1, 'from_airport' => 'LLW', 'to_airport' => 'BLZ', 'departs_local' => '08:00', 'arrives_local' => '09:00'],
            ['trip_day' => 1, 'from_airport' => 'BLZ', 'to_airport' => 'LLW', 'departs_local' => '09:40', 'arrives_local' => '10:40'],
        ]];

        $flight = $this->postJson('/api/v1/flights', $payload)->assertCreated()
            ->assertJsonPath('data.aircraft_ids', [$jet->id, $alpha->id])->assertJsonPath('data.planes.0.label', '7Q-TBC · Boeing 737-800 (B737)')->json('data');
        $this->putJson("/api/v1/flights/{$flight['id']}", collect($payload)->except('aircraft_ids')->all())->assertOk()->assertJsonPath('data.aircraft_ids', [$jet->id, $alpha->id]);
        $this->putJson("/api/v1/flights/{$flight['id']}", [...$payload, 'aircraft_ids' => [$alpha->id]])->assertOk()->assertJsonPath('data.aircraft_ids', [$alpha->id]);
        $this->getJson('/api/v1/lookups')->assertOk()->assertJsonPath('data.aircraft.1.label', '7Q-TBC · Boeing 737-800 (B737)');
        $this->putJson("/api/v1/aircraft-types/{$this->b737->id}", ['code' => 'B737', 'name' => 'Boeing 737-700', 'cabin_crew_required' => 3, 'palette' => 'gold'])->assertOk()->assertJsonPath('data.name', 'Boeing 737-700');
    }

    /** A registered, available airframe (a Q400 unless another type is given). */
    private function plane(string $registration, ?AircraftType $type = null): Aircraft
    {
        return Aircraft::factory()->create(['registration' => $registration, 'aircraft_type_id' => ($type ?? $this->q400)->id]);
    }

    /**
     * Planes that can fly a flight plan, in order of preference.
     *
     * @param  array<int, Aircraft>  $planes
     */
    private function listPlanes(Flight $flight, array $planes): void
    {
        foreach (array_values($planes) as $index => $plane) {
            $flight->aircraft()->attach($plane->id, ['position' => $index + 1]);
        }
    }
}
