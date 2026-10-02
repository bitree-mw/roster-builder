<?php

namespace Tests\Feature\Api;

use App\Models\Aircraft;
use App\Models\MaintenanceRecord;
use App\Models\RuleSet;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_alerts_list_overdue_then_due_soon_and_ignore_superseded_or_healthy_items(): void
    {
        $this->travelTo('2026-10-02 10:00:00');
        RuleSet::factory()->create(['id' => 1]);
        $first = Aircraft::factory()->create(['registration' => '7Q-AAA', 'airframe_hours' => 10000]);
        $second = Aircraft::factory()->create(['registration' => '7Q-BBB', 'airframe_hours' => 4980]);
        MaintenanceRecord::factory()->for($first)->create(['kind' => 'a_check', 'performed_on' => '2026-04-01', 'next_due_on' => '2026-09-30']);
        MaintenanceRecord::factory()->for($first)->create(['kind' => 'line_check', 'performed_on' => '2026-09-01', 'next_due_on' => '2026-09-15']);
        MaintenanceRecord::factory()->for($first)->create(['kind' => 'line_check', 'performed_on' => '2026-09-20', 'next_due_on' => '2026-12-31']);
        MaintenanceRecord::factory()->for($second)->create(['kind' => 'c_check', 'performed_on' => '2025-01-10', 'next_due_on' => null, 'airframe_hours_at' => 2000, 'next_due_hours' => 5000]);
        MaintenanceRecord::factory()->for($second)->create(['kind' => 'inspection', 'performed_on' => '2026-09-01', 'next_due_on' => '2026-10-10']);
        Sanctum::actingAs(User::factory()->create(['role' => 'crew_control']), ['roster:read']);

        $this->getJson('/api/v1/maintenance-alerts')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.state', 'overdue')->assertJsonPath('data.0.days_remaining', -2)->assertJsonPath('data.0.record.aircraft.registration', '7Q-AAA')
            ->assertJsonPath('data.1.state', 'due_soon')->assertJsonPath('data.1.days_remaining', 8)->assertJsonPath('data.1.record.kind', 'inspection')
            ->assertJsonPath('data.2.state', 'due_soon')->assertJsonPath('data.2.hours_remaining', 20)->assertJsonPath('data.2.record.kind', 'c_check');
    }

    public function test_hours_overdue_outranks_a_healthy_due_date(): void
    {
        $this->travelTo('2026-10-02 10:00:00');
        $aircraft = Aircraft::factory()->create(['airframe_hours' => 5010]);
        MaintenanceRecord::factory()->for($aircraft)->create(['next_due_on' => '2027-06-01', 'airframe_hours_at' => 4000, 'next_due_hours' => 5000]);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['roster:read']);

        $this->getJson('/api/v1/maintenance-alerts')->assertOk()->assertJsonPath('data.0.state', 'overdue')->assertJsonPath('data.0.hours_remaining', -10);
    }

    public function test_due_dates_use_the_base_local_calendar_date(): void
    {
        $this->travelTo('2026-10-01 23:00:00');
        RuleSet::factory()->create(['id' => 1, 'utc_offset_minutes' => 120]);
        MaintenanceRecord::factory()->create(['performed_on' => '2026-09-01', 'next_due_on' => '2026-10-01']);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['roster:read']);

        $this->getJson('/api/v1/maintenance-alerts')->assertOk()->assertJsonPath('data.0.state', 'overdue')->assertJsonPath('data.0.days_remaining', -1);
    }

    public function test_aircraft_list_includes_its_maintenance_due_items(): void
    {
        $this->travelTo('2026-10-02 10:00:00');
        $record = MaintenanceRecord::factory()->create(['performed_on' => '2026-09-01', 'next_due_on' => '2026-10-05']);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['roster:read']);

        $this->getJson('/api/v1/aircraft')->assertOk()
            ->assertJsonPath('data.0.id', $record->aircraft_id)->assertJsonPath('data.0.maintenance_due.0.state', 'due_soon')->assertJsonPath('data.0.maintenance_due.0.days_remaining', 3);
    }

    public function test_staff_can_record_maintenance_with_audit_and_recorder(): void
    {
        $aircraft = Aircraft::factory()->create();
        $user = User::factory()->create(['role' => 'crew_control', 'name' => 'Engineering Desk']);
        Sanctum::actingAs($user, ['*']);
        $this->postJson('/api/v1/maintenance-records', ['aircraft_id' => $aircraft->id, 'kind' => 'a_check', 'title' => 'A-check 4A', 'performed_on' => '2026-09-28', 'airframe_hours_at' => 15000, 'next_due_on' => '2027-01-28', 'next_due_hours' => 15600, 'recorded_by' => 999])
            ->assertCreated()->assertJsonPath('data.kind_label', 'A-check')->assertJsonPath('data.next_due_on', '2027-01-28');
        $this->assertDatabaseHas('maintenance_records', ['aircraft_id' => $aircraft->id, 'title' => 'A-check 4A', 'recorded_by' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'maintenance_records', 'action' => 'created']);
    }

    public function test_next_due_hours_must_exceed_hours_at_service(): void
    {
        $aircraft = Aircraft::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/maintenance-records', ['aircraft_id' => $aircraft->id, 'kind' => 'c_check', 'title' => 'C-check', 'performed_on' => '2026-09-28', 'airframe_hours_at' => 15000, 'next_due_hours' => 15000])
            ->assertUnprocessable()->assertJsonPath('errors.next_due_hours.0', 'The next due hours must be greater than the airframe hours when the work was performed.');
        $this->assertDatabaseCount('maintenance_records', 0);
    }

    public function test_next_due_date_must_follow_performed_date(): void
    {
        $aircraft = Aircraft::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/maintenance-records', ['aircraft_id' => $aircraft->id, 'kind' => 'a_check', 'title' => 'A-check', 'performed_on' => '2026-09-28', 'next_due_on' => '2026-09-01'])
            ->assertUnprocessable()->assertJsonPath('errors.next_due_on.0', 'The next due date must be after the date the work was performed.');
    }

    public function test_crew_cannot_read_or_write_maintenance(): void
    {
        $aircraft = Aircraft::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'crew']), ['*']);
        $this->getJson('/api/v1/maintenance-alerts')->assertForbidden();
        $this->getJson('/api/v1/maintenance-records')->assertForbidden();
        $this->postJson('/api/v1/maintenance-records', ['aircraft_id' => $aircraft->id, 'kind' => 'a_check', 'title' => 'A-check', 'performed_on' => '2026-09-28'])->assertForbidden();
        $this->assertDatabaseCount('maintenance_records', 0);
    }
}
