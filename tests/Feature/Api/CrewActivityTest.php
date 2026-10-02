<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\CrewActivity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Day planning (leave, day off, SIM, standby): date ranges, local times stored as UTC, refusal of days that
 * are already planned, conflicts on rostered seats, and standby cleared by a manual flight assignment.
 */
class CrewActivityTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
    }

    public function test_range_is_planned_per_day_with_local_times_stored_as_utc(): void
    {
        $crew = $this->crew('CPT', 'Alpha Captain');
        $this->actingAsScheduler();

        $this->postJson('/api/v1/crew-activities', ['crew_member_id' => $crew->id, 'type' => 'leave', 'date_from' => '2026-10-12', 'date_to' => '2026-10-14'])->assertCreated()
            ->assertJsonCount(3, 'data')->assertJsonPath('message', 'Leave (3 days) planned for Alpha Captain.');
        $this->postJson('/api/v1/crew-activities', ['crew_member_id' => $crew->id, 'type' => 'sim', 'date_from' => '2026-10-15', 'date_to' => '2026-10-15', 'starts_local' => '06:00', 'ends_local' => '10:00'])->assertCreated()
            ->assertJsonPath('data.0.starts_at', '2026-10-15T04:00:00+00:00')->assertJsonPath('data.0.ends_at', '2026-10-15T08:00:00+00:00');
        $this->postJson('/api/v1/crew-activities', ['crew_member_id' => $crew->id, 'type' => 'standby', 'date_from' => '2026-10-14', 'date_to' => '2026-10-16'])->assertUnprocessable()
            ->assertJsonPath('errors.date_from.0', 'Already planned on 14 Oct (Leave), 15 Oct (Simulator session). Remove those first.');
        $this->postJson('/api/v1/crew-activities', ['crew_member_id' => $crew->id, 'type' => 'leave', 'date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'starts_local' => '06:00', 'ends_local' => '10:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_local');
        $this->assertSame(4, CrewActivity::count());

        $this->deleteJson('/api/v1/crew-activities/'.CrewActivity::firstWhere('type', 'sim')->id)->assertOk()->assertJsonPath('message', 'Simulator session on 15 Oct for Alpha Captain removed.');
        $this->assertSame(3, CrewActivity::count());
    }

    public function test_leave_over_a_rostered_seat_shows_as_a_conflict(): void
    {
        $this->flight('LB1', [0]);
        $crew = $this->crew('CPT', 'Alpha Captain');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();

        $this->postJson('/api/v1/crew-activities', ['crew_member_id' => $crew->id, 'type' => 'leave', 'date_from' => '2026-10-05', 'date_to' => '2026-10-05'])->assertCreated();
        $this->getJson("/api/v1/roster-periods/{$period->id}")->assertJsonPath('data.summary.blocking', 1)->assertJsonPath('data.conflicts.0.message', 'On leave on 05 Oct.');
    }

    public function test_assigning_a_flight_clears_standby_on_that_day(): void
    {
        $this->flight('LB1', [0]);
        $this->crew('CPT', 'Alpha Captain');
        $bravo = $this->crew('CPT', 'Bravo Captain');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        // Bravo has no flights, so the build planned standby (Monday is the first free day).
        $standby = $bravo->activities()->where('date', '2026-10-05')->where('type', 'standby')->whereNotNull('roster_period_id')->firstOrFail();
        $seat = Assignment::firstWhere('rank', 'CPT');

        $candidate = collect($this->getJson("/api/v1/assignments/{$seat->id}/candidates")->json('data'))->firstWhere('crew_member_id', $bravo->id);
        $this->assertTrue($candidate['legal']);
        $this->assertTrue($candidate['clears_standby']);
        $this->putJson("/api/v1/assignments/{$seat->id}", ['crew_member_id' => $bravo->id])->assertOk()->assertJsonPath('data.decision_log.cleared_standby', ['2026-10-05']);
        $this->assertModelMissing($standby);
    }
}
