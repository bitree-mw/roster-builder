<?php

namespace Tests\Feature\Api;

use App\Models\AircraftType;
use App\Models\Airport;
use App\Models\RuleSet;
use App\Models\User;
use App\Services\FlightTimelineService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Flight pattern saving: connected legs, night-stop rest, overnight duty with UTC conversion, and that
 * invalid patterns leave no partial writes.
 */
class FlightTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function flightPayload(): array
    {
        Airport::factory()->create(['code' => 'LLW']);
        Airport::factory()->create(['code' => 'BLZ', 'is_base' => false]);
        RuleSet::factory()->create(['id' => 1]);

        return ['code' => 'LB1', 'aircraft_type_id' => AircraftType::factory()->create()->id, 'active' => true, 'weekdays' => [0, 2, 4], 'legs' => [
            ['trip_day' => 1, 'from_airport' => 'LLW', 'to_airport' => 'BLZ', 'departs_local' => '08:00', 'arrives_local' => '09:00'],
            ['trip_day' => 1, 'from_airport' => 'BLZ', 'to_airport' => 'LLW', 'departs_local' => '10:00', 'arrives_local' => '11:00'],
        ]];
    }

    public function test_connected_round_trip_is_persisted_with_days_and_legs(): void
    {
        $payload = $this->flightPayload();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/flights', $payload)->assertCreated()->assertJsonPath('data.weekdays', [0, 2, 4])->assertJsonPath('data.legs.1.arrives_local', '11:00');
        $this->assertDatabaseCount('flight_legs', 2);
        $this->assertDatabaseCount('flight_days', 3);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_disconnected_legs_fail_without_partial_writes(): void
    {
        $payload = $this->flightPayload();
        $payload['legs'][1]['from_airport'] = 'LLW';
        $payload['legs'][1]['to_airport'] = 'BLZ';
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/flights', $payload)->assertUnprocessable()->assertJsonPath('errors.legs.0', 'Legs must connect at the same airport.');
        $this->assertDatabaseCount('flights', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_night_stop_with_insufficient_rest_is_rejected(): void
    {
        $payload = $this->flightPayload();
        $payload['legs'][0]['departs_local'] = '19:00';
        $payload['legs'][0]['arrives_local'] = '20:00';
        $payload['legs'][1]['trip_day'] = 2;
        $payload['legs'][1]['departs_local'] = '06:00';
        $payload['legs'][1]['arrives_local'] = '07:00';
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/flights', $payload)->assertUnprocessable()->assertJsonPath('errors.legs.0', 'The night stop does not provide the required minimum rest.');
        $this->assertDatabaseCount('flights', 0);
    }

    public function test_rotation_with_several_night_stops_at_outstations_is_saved_beyond_four_days(): void
    {
        $payload = $this->flightPayload();
        Airport::factory()->create(['code' => 'MZU', 'is_base' => false]);
        // LLW → BLZ (night) → MZU (night) → BLZ (night) → MZU (night) → LLW: five trip days, same crew throughout.
        $payload['legs'] = [
            ['trip_day' => 1, 'from_airport' => 'LLW', 'to_airport' => 'BLZ', 'departs_local' => '08:00', 'arrives_local' => '09:00'],
            ['trip_day' => 2, 'from_airport' => 'BLZ', 'to_airport' => 'MZU', 'departs_local' => '08:00', 'arrives_local' => '09:00'],
            ['trip_day' => 3, 'from_airport' => 'MZU', 'to_airport' => 'BLZ', 'departs_local' => '08:00', 'arrives_local' => '09:00'],
            ['trip_day' => 4, 'from_airport' => 'BLZ', 'to_airport' => 'MZU', 'departs_local' => '08:00', 'arrives_local' => '09:00'],
            ['trip_day' => 5, 'from_airport' => 'MZU', 'to_airport' => 'LLW', 'departs_local' => '08:00', 'arrives_local' => '09:00'],
        ];
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/flights', $payload)->assertCreated()->assertJsonPath('data.legs.4.trip_day', 5);
        $this->assertDatabaseCount('flight_legs', 5);
    }

    public function test_night_stop_at_base_or_beyond_the_rotation_limit_is_rejected(): void
    {
        $payload = $this->flightPayload();
        // Back at LLW (base) on day 1, then flying again on day 2: crew change at base, so this is a new route.
        $payload['legs'] = [
            ...$payload['legs'],
            ['trip_day' => 2, 'from_airport' => 'LLW', 'to_airport' => 'BLZ', 'departs_local' => '08:00', 'arrives_local' => '09:00'],
            ['trip_day' => 2, 'from_airport' => 'BLZ', 'to_airport' => 'LLW', 'departs_local' => '10:00', 'arrives_local' => '11:00'],
        ];
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/flights', $payload)->assertUnprocessable()
            ->assertJsonPath('errors.legs.0', 'A night stop must be at an outstation. The trip ends when it is back at base (crew change there), so add later flying as a separate route.');

        // A night stop at the outstation is fine in itself, but not when rotations are limited to one day.
        config(['roster.max_trip_days' => 1]);
        $payload['legs'] = array_slice($payload['legs'], 0, 2);
        $payload['legs'][1] = [...$payload['legs'][1], 'trip_day' => 2, 'departs_local' => '09:00', 'arrives_local' => '10:00'];
        $this->postJson('/api/v1/flights', $payload)->assertUnprocessable()->assertJsonValidationErrors('legs.1.trip_day');
        $this->assertDatabaseCount('flights', 0);
    }

    public function test_gmt_leg_times_are_stored_in_base_time_and_returned_in_both(): void
    {
        $payload = $this->flightPayload();
        RuleSet::findOrFail(1)->update(['utc_offset_minutes' => 120]);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/flights', [...$payload, 'time_zone' => 'utc'])->assertCreated()
            ->assertJsonPath('data.legs.0.departs_local', '10:00')->assertJsonPath('data.legs.0.departs_utc', '08:00')->assertJsonPath('data.legs.1.arrives_utc', '11:00');
    }

    public function test_overnight_duty_and_utc_conversion_keep_date_boundaries(): void
    {
        $payload = $this->flightPayload();
        $payload['legs'][0]['departs_local'] = '22:00';
        $payload['legs'][0]['arrives_local'] = '23:30';
        $payload['legs'][1]['departs_local'] = '00:15';
        $payload['legs'][1]['arrives_local'] = '01:30';
        $service = new FlightTimelineService;
        $result = $service->validate($payload['legs'], RuleSet::findOrFail(1));
        $this->assertSame(1560, $result['periods'][0]['release_min']);
        $this->assertSame(165, $result['periods'][0]['block_min']);
        $this->assertSame('2026-09-30T23:00:00+00:00', $service->instant('2026-10-01', 60, 120));
    }

    public function test_duplicate_weekdays_and_overlong_duty_fail(): void
    {
        $payload = $this->flightPayload();
        $payload['weekdays'] = [0, 0];
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/flights', $payload)->assertUnprocessable()->assertJsonValidationErrors('weekdays.0');
        $payload['weekdays'] = [0];
        $payload['legs'][1]['departs_local'] = '21:00';
        $payload['legs'][1]['arrives_local'] = '22:00';
        $this->postJson('/api/v1/flights', $payload)->assertUnprocessable()->assertJsonPath('errors.legs.0', 'A duty exceeds the configured maximum daily duty.');
    }
}
