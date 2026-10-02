<?php

namespace Tests\Feature\Api;

use App\Models\Aircraft;
use App\Models\CrewDocument;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_overview_counts_fleet_status_flights_maintenance_and_active_crew_documents(): void
    {
        $this->travelTo('2026-10-02 10:00:00');
        Aircraft::factory()->create();
        Aircraft::factory()->grounded()->create();
        Flight::factory()->create(['active' => false]);
        MaintenanceRecord::factory()->create(['performed_on' => '2026-01-01', 'next_due_on' => '2026-09-01']);
        $crew = CrewMember::factory()->create(['rank' => 'CPT', 'active' => true]);
        CrewDocument::factory()->for($crew)->create(['kind' => 'medical', 'expires_on' => '2026-09-30']);
        CrewDocument::factory()->for($crew)->create(['kind' => 'licence', 'expires_on' => '2026-10-20']);
        $inactive = CrewMember::factory()->create(['active' => false]);
        CrewDocument::factory()->for($inactive)->create(['expires_on' => '2026-01-01']);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['roster:read']);

        $this->getJson('/api/v1/overview')->assertOk()
            ->assertJsonPath('data.fleet.total', 3)->assertJsonPath('data.fleet.available', 2)->assertJsonPath('data.fleet.grounded', 1)
            ->assertJsonPath('data.flights.disabled', 1)->assertJsonPath('data.flights.enabled', 0)
            ->assertJsonPath('data.maintenance.overdue', 1)
            ->assertJsonPath('data.crew.captains', 1)->assertJsonPath('data.crew.documents_expired', 1)->assertJsonPath('data.crew.documents_due_soon', 1);
    }

    public function test_crew_cannot_read_overview(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'crew']), ['*']);
        $this->getJson('/api/v1/overview')->assertForbidden();
    }

    public function test_crew_directory_reports_document_expiry_state(): void
    {
        $this->travelTo('2026-10-02 10:00:00');
        $crew = CrewMember::factory()->create();
        CrewDocument::factory()->for($crew)->create(['kind' => 'medical', 'expires_on' => '2026-10-12']);
        Sanctum::actingAs(User::factory()->create(['role' => 'crew_control']), ['roster:read']);

        $this->getJson('/api/v1/crew-members/'.$crew->id)->assertOk()
            ->assertJsonPath('data.documents.0.state', 'due_soon')->assertJsonPath('data.documents.0.days_remaining', 10);
    }
}
