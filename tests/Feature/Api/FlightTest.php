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
