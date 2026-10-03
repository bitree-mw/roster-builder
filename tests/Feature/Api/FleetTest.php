<?php

namespace Tests\Feature\Api;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Flight;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Airframes and flight enable/disable: registration rules, status changes with reasons and audit,
 * authorization, deletion guards and airframe availability counts on flights.
 */
class FleetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_new_aircraft_starts_available_and_ignores_status_in_payload(): void
    {
        $type = AircraftType::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/aircraft', ['registration' => ' 7q-tba ', 'aircraft_type_id' => $type->id, 'airframe_hours' => 15234.5, 'status' => 'grounded'])
            ->assertCreated()->assertJsonPath('data.registration', '7Q-TBA')->assertJsonPath('data.status', 'available');
        $this->assertDatabaseHas('aircraft', ['registration' => '7Q-TBA', 'status' => 'available']);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'aircraft', 'action' => 'created']);
    }

    public function test_aircraft_registration_must_be_unique(): void
    {
        $existing = Aircraft::factory()->create(['registration' => '7Q-TBA']);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/aircraft', ['registration' => '7Q-TBA', 'aircraft_type_id' => $existing->aircraft_type_id, 'airframe_hours' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('registration');
        $this->assertDatabaseCount('aircraft', 1);
    }

    public function test_grounding_aircraft_records_reason_and_audit(): void
    {
        $aircraft = Aircraft::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'crew_control']), ['*']);
        $this->patchJson('/api/v1/aircraft/'.$aircraft->id.'/status', ['status' => 'grounded', 'reason' => 'Bird strike inspection'])
            ->assertOk()->assertJsonPath('data.status', 'grounded')->assertJsonPath('data.status_label', 'Not available (AOG)')->assertJsonPath('data.status_reason', 'Bird strike inspection');
        $this->assertDatabaseHas('aircraft', ['id' => $aircraft->id, 'status' => 'grounded', 'status_reason' => 'Bird strike inspection']);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'aircraft', 'entity_id' => $aircraft->id, 'action' => 'status_changed']);
    }

    public function test_aircraft_can_be_marked_not_available_without_a_reason(): void
    {
        $aircraft = Aircraft::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->patchJson('/api/v1/aircraft/'.$aircraft->id.'/status', ['status' => 'unavailable', 'reason' => ''])
            ->assertOk()->assertJsonPath('data.status', 'unavailable')->assertJsonPath('data.status_reason', null);
        $this->assertDatabaseHas('aircraft', ['id' => $aircraft->id, 'status' => 'unavailable', 'status_reason' => null]);
    }

    public function test_unknown_status_returns_422(): void
    {
        $aircraft = Aircraft::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->patchJson('/api/v1/aircraft/'.$aircraft->id.'/status', ['status' => 'scrapped', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_returning_aircraft_to_service_clears_reason(): void
    {
        $aircraft = Aircraft::factory()->grounded()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->patchJson('/api/v1/aircraft/'.$aircraft->id.'/status', ['status' => 'available'])->assertOk()->assertJsonPath('data.status_reason', null);
        $this->assertDatabaseHas('aircraft', ['id' => $aircraft->id, 'status' => 'available', 'status_reason' => null]);
    }

    public function test_crew_cannot_change_aircraft_status(): void
    {
        $aircraft = Aircraft::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'crew']), ['*']);
        $this->patchJson('/api/v1/aircraft/'.$aircraft->id.'/status', ['status' => 'grounded', 'reason' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/aircraft')->assertForbidden();
        $this->assertDatabaseHas('aircraft', ['id' => $aircraft->id, 'status' => 'available']);
    }

    public function test_aircraft_with_maintenance_history_cannot_be_deleted(): void
    {
        $record = MaintenanceRecord::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->deleteJson('/api/v1/aircraft/'.$record->aircraft_id)->assertUnprocessable()
            ->assertJsonPath('errors.aircraft.0', 'This aircraft has maintenance history. Mark it unavailable instead.');
        $this->assertDatabaseHas('aircraft', ['id' => $record->aircraft_id]);
    }

    public function test_aircraft_type_with_registered_airframes_cannot_be_deleted(): void
    {
        $aircraft = Aircraft::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->deleteJson('/api/v1/aircraft-types/'.$aircraft->aircraft_type_id)->assertUnprocessable()->assertJsonValidationErrors('aircraft_type');
        $this->assertDatabaseHas('aircraft_types', ['id' => $aircraft->aircraft_type_id]);
    }

    public function test_flight_can_be_disabled_and_enabled_with_audit(): void
    {
        $flight = Flight::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'crew_control']), ['*']);
        $this->patchJson('/api/v1/flights/'.$flight->id.'/status', ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->assertDatabaseHas('flights', ['id' => $flight->id, 'active' => false]);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'flights', 'entity_id' => $flight->id, 'action' => 'disabled']);
        $this->patchJson('/api/v1/flights/'.$flight->id.'/status', ['active' => true])->assertOk()->assertJsonPath('data.active', true);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'flights', 'entity_id' => $flight->id, 'action' => 'enabled']);
    }

    public function test_flight_status_requires_boolean(): void
    {
        $flight = Flight::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->patchJson('/api/v1/flights/'.$flight->id.'/status', [])->assertUnprocessable()->assertJsonValidationErrors('active');
        $this->assertDatabaseHas('flights', ['id' => $flight->id, 'active' => true]);
    }

    public function test_crew_cannot_disable_flight(): void
    {
        $flight = Flight::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'crew']), ['*']);
        $this->patchJson('/api/v1/flights/'.$flight->id.'/status', ['active' => false])->assertForbidden();
        $this->assertDatabaseHas('flights', ['id' => $flight->id, 'active' => true]);
    }

    public function test_flights_report_available_airframes_for_their_aircraft_type(): void
    {
        $flight = Flight::factory()->create();
        Aircraft::factory()->for($flight->aircraftType)->create();
        Aircraft::factory()->grounded()->for($flight->aircraftType)->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['roster:read']);
        $this->getJson('/api/v1/flights')->assertOk()
            ->assertJsonPath('data.0.aircraft.aircraft_count', 2)->assertJsonPath('data.0.aircraft.available_aircraft_count', 1);
    }
}
