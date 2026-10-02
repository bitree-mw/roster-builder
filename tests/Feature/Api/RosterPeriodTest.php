<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\RosterPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Roster weeks: idempotent Monday-to-Sunday draft creation with a rules snapshot, the planning window, the
 * week timeline, conflicts that appear after a build blocking publication, reopening, and crew seeing only
 * their own seats in published weeks.
 */
class RosterPeriodTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
    }

    public function test_creating_a_week_is_idempotent_and_snapshots_rules(): void
    {
        $this->actingAsScheduler();
        $this->postJson('/api/v1/roster-periods', ['week_start' => '2026-10-05', 'status' => 'published'])->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.ends_on', '2026-10-11')->assertJsonPath('data.iso_week', 41)->assertJsonPath('data.editable', true);
        $this->postJson('/api/v1/roster-periods', ['week_start' => '2026-10-05'])->assertOk();
        $this->assertDatabaseCount('roster_periods', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame(13, (int) RosterPeriod::first()->rules_snapshot['max_duty_day_h']);
    }

    public function test_week_must_start_on_a_monday_inside_the_planning_window(): void
    {
        $this->actingAsScheduler();
        $this->postJson('/api/v1/roster-periods', ['week_start' => '2026-10-07'])->assertUnprocessable()->assertJsonPath('errors.week_start.0', 'A roster week must start on a Monday.');
        $this->postJson('/api/v1/roster-periods', ['week_start' => '2029-01-01'])->assertUnprocessable()->assertJsonValidationErrors('week_start');
        $this->assertDatabaseCount('roster_periods', 0);
    }

    public function test_timeline_lists_past_and_upcoming_weeks_with_seat_counts(): void
    {
        $this->flight('LB1', [0]);
        $this->crew('CPT', 'Alpha Captain');
        $past = $this->week('2026-09-21', 'published');
        $upcoming = $this->week('2026-10-05');
        $this->week('2027-06-07');
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$upcoming->id}/build")->assertOk();

        $this->getJson('/api/v1/roster-periods?from=2026-09-01&to=2026-11-30')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $past->id)->assertJsonPath('data.0.ended', true)->assertJsonPath('data.0.editable', false)
            ->assertJsonPath('data.1.id', $upcoming->id)->assertJsonPath('data.1.seats_count', 3)->assertJsonPath('data.1.open_seats_count', 2);
        $this->getJson('/api/v1/roster-periods?from=2026-01-05&to=2027-12-27')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_conflict_after_build_blocks_publishing_until_resolved_then_reopen_hides_it_from_crew(): void
    {
        $this->flight('LB1', [0]);
        $alpha = $this->crew('CPT', 'Alpha Captain');
        $bravo = $this->crew('CPT', 'Bravo Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/publish")->assertUnprocessable()->assertJsonPath('errors.period.0', 'Build this roster before publishing it.');
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();

        // Leave recorded after the build makes Alpha's seat illegal.
        $alpha->activities()->create(['date' => '2026-10-05', 'type' => 'leave']);
        $this->getJson("/api/v1/roster-periods/{$period->id}")->assertOk()->assertJsonPath('data.summary.blocking', 1)
            ->assertJsonPath('data.conflicts.0.code', 'unavailable')->assertJsonPath('data.conflicts.0.crew_name', 'Alpha Captain');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.conflict_total', 1)->assertJsonPath('data.weeks.2.summary.blocking', 1);
        $this->postJson("/api/v1/roster-periods/{$period->id}/publish")->assertUnprocessable();

        $seat = Assignment::firstWhere('crew_member_id', $alpha->id);
        $this->putJson("/api/v1/assignments/{$seat->id}", ['crew_member_id' => $bravo->id])->assertOk();
        $this->postJson("/api/v1/roster-periods/{$period->id}/publish")->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.editable', false);
        $this->putJson("/api/v1/assignments/{$seat->id}", ['crew_member_id' => null])->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->postJson("/api/v1/roster-periods/{$period->id}/reopen")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('audit_logs', ['entity' => 'roster_periods', 'action' => 'reopened']);
    }

    public function test_crew_only_receive_their_own_seats_in_published_weeks(): void
    {
        $this->flight('LB1', [0, 2]);
        $captain = $this->crew('CPT', 'Alpha Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $published = $this->week();
        $draft = $this->week('2026-10-12');
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$published->id}/build")->assertOk();
        $this->postJson("/api/v1/roster-periods/{$published->id}/publish")->assertOk();
        $this->postJson("/api/v1/roster-periods/{$draft->id}/build")->assertOk();

        Sanctum::actingAs(User::factory()->create(['role' => 'crew', 'crew_member_id' => $captain->id]), ['roster:read']);
        $this->getJson('/api/v1/roster-periods?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.seats_count');
        $week = $this->getJson("/api/v1/roster-periods/{$published->id}")->assertOk()
            ->assertJsonCount(2, 'data.trips')->assertJsonCount(1, 'data.trips.0.assignments')->assertJsonCount(1, 'data.crew')
            ->assertJsonPath('data.trips.0.assignments.0.crew_member_id', $captain->id)->assertJsonPath('data.trips.0.assignments.0.decision_log', null)
            ->assertJsonPath('data.conflicts', [])->assertJsonPath('data.summary.duties', 2);
        $this->assertStringNotContainsString('Charlie Officer', $week->getContent());
        $this->getJson("/api/v1/roster-periods/{$draft->id}")->assertNotFound();
        $this->getJson('/api/v1/dashboard')->assertForbidden();
        $this->postJson('/api/v1/roster-periods', ['week_start' => '2026-10-19'])->assertForbidden();
    }
}
