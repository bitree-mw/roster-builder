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
 * Roster periods: idempotent draft creation (1 week, 2 weeks or a calendar month) with a rules snapshot, start
 * day and overlap rules, the planning window, the timeline, conflicts that appear after a build blocking
 * publication, reopening, and crew seeing only their own seats in published rosters.
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
        $this->postJson('/api/v1/roster-periods', ['starts_on' => '2026-10-05', 'length' => 'week', 'status' => 'published'])->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.ends_on', '2026-10-11')->assertJsonPath('data.iso_week', 41)->assertJsonPath('data.editable', true);
        $this->postJson('/api/v1/roster-periods', ['starts_on' => '2026-10-05', 'length' => 'week'])->assertOk();
        $this->assertDatabaseCount('roster_periods', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame(13, (int) RosterPeriod::first()->rules_snapshot['max_duty_day_h']);
    }

    public function test_week_must_start_on_a_monday_inside_the_planning_window(): void
    {
        $this->actingAsScheduler();
        $this->postJson('/api/v1/roster-periods', ['starts_on' => '2026-10-07', 'length' => 'fortnight'])->assertUnprocessable()->assertJsonPath('errors.starts_on.0', 'Weekly and two-week rosters start on a Monday.');
        $this->postJson('/api/v1/roster-periods', ['starts_on' => '2026-10-05', 'length' => 'month'])->assertUnprocessable()->assertJsonPath('errors.starts_on.0', 'A monthly roster starts on the 1st of the month.');
        $this->postJson('/api/v1/roster-periods', ['starts_on' => '2029-01-01', 'length' => 'week'])->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->postJson('/api/v1/roster-periods', ['starts_on' => '2026-10-05', 'length' => 'year'])->assertUnprocessable()->assertJsonValidationErrors('length');
        $this->assertDatabaseCount('roster_periods', 0);
    }

    public function test_fortnight_and_month_rosters_cover_their_dates_and_cannot_overlap(): void
    {
        $this->actingAsScheduler();
        $this->postJson('/api/v1/roster-periods', ['starts_on' => '2026-10-05', 'length' => 'fortnight'])->assertCreated()
            ->assertJsonPath('data.length', 'fortnight')->assertJsonPath('data.ends_on', '2026-10-18')->assertJsonPath('data.days', 14);
        $this->postJson('/api/v1/roster-periods', ['starts_on' => '2026-11-01', 'length' => 'month'])->assertCreated()
            ->assertJsonPath('data.ends_on', '2026-11-30')->assertJsonPath('data.days', 30);

        // A week inside the fortnight, or a fortnight running into November, overlaps an existing roster.
        $this->postJson('/api/v1/roster-periods', ['starts_on' => '2026-10-12', 'length' => 'week'])->assertUnprocessable()
            ->assertJsonPath('errors.starts_on.0', 'These dates overlap the roster for weeks 41–42 (05–18 Oct 2026). Choose dates after 18 Oct 2026, or open that roster.');
        $this->postJson('/api/v1/roster-periods', ['starts_on' => '2026-10-26', 'length' => 'fortnight'])->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->assertDatabaseCount('roster_periods', 2);
    }

    public function test_building_a_fortnight_plans_trips_on_every_operating_day_in_both_weeks(): void
    {
        $this->flight('LB1', [0]);
        $this->crew('CPT', 'Alpha Captain');
        $this->actingAsScheduler();
        $id = $this->postJson('/api/v1/roster-periods', ['starts_on' => '2026-10-05', 'length' => 'fortnight'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/roster-periods/{$id}/build")->assertOk()->assertJsonPath('data.summary.seats', 6);
        $this->assertEqualsCanonicalizing(['2026-10-05', '2026-10-12'], RosterPeriod::findOrFail($id)->trips()->pluck('start_date')->map(fn ($date): string => $date->format('Y-m-d'))->all());
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
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.conflict_total', 1)->assertJsonPath('data.weeks.1.slot', 'next')->assertJsonPath('data.weeks.1.summary.blocking', 1);
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
