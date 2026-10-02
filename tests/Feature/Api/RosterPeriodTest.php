<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\CrewMember;
use App\Models\RosterPeriod;
use App\Models\RuleSet;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roster periods: idempotent draft creation with a rules snapshot, the planning window, and crew seeing
 * only their own assignments in published periods.
 */
class RosterPeriodTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creating_draft_is_idempotent_and_snapshots_rules(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        RuleSet::factory()->create(['id' => 1]);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/roster-periods', ['month' => '2026-10', 'status' => 'published'])->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->postJson('/api/v1/roster-periods', ['month' => '2026-10'])->assertOk();
        $this->assertDatabaseCount('roster_periods', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame(13, (int) RosterPeriod::first()->rules_snapshot['max_duty_day_h']);
    }

    public function test_month_outside_planning_window_is_rejected(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/roster-periods', ['month' => '2029-01'])->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->assertDatabaseCount('roster_periods', 0);
    }

    public function test_crew_only_receive_their_assignments_in_published_periods(): void
    {
        $crew = CrewMember::factory()->create();
        $other = CrewMember::factory()->create();
        $period = RosterPeriod::factory()->create(['status' => 'published']);
        $trip = Trip::factory()->create(['roster_period_id' => $period->id]);
        $own = Assignment::factory()->create(['trip_id' => $trip->id, 'crew_member_id' => $crew->id]);
        Assignment::factory()->create(['trip_id' => $trip->id, 'crew_member_id' => $other->id, 'rank' => 'FO']);
        RosterPeriod::factory()->create(['month' => '2026-11-01', 'status' => 'draft']);
        Sanctum::actingAs(User::factory()->create(['role' => 'crew', 'crew_member_id' => $crew->id]), ['roster:read']);
        $this->getJson('/api/v1/roster-periods')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'data.0.trips.0.assignments')->assertJsonPath('data.0.trips.0.assignments.0.id', $own->id);
        $this->postJson('/api/v1/roster-periods', ['month' => '2026-12'])->assertForbidden();
    }
}
