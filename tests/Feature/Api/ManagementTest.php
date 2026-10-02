<?php

namespace Tests\Feature\Api;

use App\Models\AircraftType;
use App\Models\Airport;
use App\Models\CrewMember;
use App\Models\RosterPeriod;
use App\Models\RuleSet;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_update_replaces_ratings_and_documents_and_filters_directory(): void
    {
        $base = Airport::factory()->create();
        $aircraft = AircraftType::factory()->create();
        $crew = CrewMember::factory()->create(['base_airport' => $base->code]);
        $crew->ratings()->attach($aircraft);
        $crew->documents()->create(['kind' => 'licence', 'expires_on' => '2027-01-01']);
        Sanctum::actingAs(User::factory()->create(['role' => 'crew_control']), ['*']);
        $this->putJson('/api/v1/crew-members/'.$crew->id, ['name' => 'Cabin Example', 'email' => null, 'rank' => 'CC', 'base_airport' => $base->code, 'active' => true, 'all_aircraft' => true, 'rating_ids' => [], 'documents' => []])
            ->assertOk()->assertJsonPath('data.all_aircraft', true)->assertJsonCount(0, 'data.documents');
        $this->assertDatabaseCount('crew_ratings', 0);
        $this->assertDatabaseCount('crew_documents', 0);
        $this->getJson('/api/v1/crew-members?rank=CPT')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/crew-members?rank=CC&base='.$base->code)->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/crew-members/'.$crew->id)->assertNoContent();
        $this->assertModelMissing($crew);
    }

    public function test_crew_with_linked_account_cannot_be_deleted(): void
    {
        $crew = CrewMember::factory()->create();
        User::factory()->create(['crew_member_id' => $crew->id]);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->deleteJson('/api/v1/crew-members/'.$crew->id)->assertUnprocessable()->assertJsonValidationErrors('crew_member');
        $this->assertModelExists($crew);
    }

    public function test_scheduler_rule_changes_preserve_existing_period_snapshot(): void
    {
        $rules = RuleSet::factory()->create(['id' => 1]);
        $period = RosterPeriod::factory()->create(['rules_snapshot' => $rules->toArray()]);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->putJson('/api/v1/rules', [...$rules->toArray(), 'max_duty_day_h' => 12])->assertOk()->assertJsonPath('data.max_duty_day_h', 12);
        $this->assertDatabaseHas('rule_sets', ['id' => 1, 'max_duty_day_h' => 12]);
        $this->assertSame(13, (int) $period->fresh()->rules_snapshot['max_duty_day_h']);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'rule_sets', 'action' => 'updated']);
    }

    public function test_invalid_rules_leave_configuration_unchanged(): void
    {
        $rules = RuleSet::factory()->create(['id' => 1]);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->putJson('/api/v1/rules', [...$rules->toArray(), 'max_days_off_week' => 8, 'utc_offset_minutes' => 125])
            ->assertUnprocessable()->assertJsonValidationErrors(['max_days_off_week', 'utc_offset_minutes']);
        $this->assertDatabaseHas('rule_sets', ['max_days_off_week' => 4, 'utc_offset_minutes' => 120]);
    }
}
