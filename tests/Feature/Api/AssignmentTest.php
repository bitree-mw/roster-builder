<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\RosterPeriod;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Manual seat edits in the roster window: candidates with legal choices first, rule-breaking assignments
 * needing a recorded override, problems that can never be overridden, clearing a seat, and trips that have
 * already operated.
 */
class AssignmentTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    private RosterPeriod $period;

    /** Two shuttles: LB2 Monday evening (release 23:10) and LB1 Tuesday morning (report 07:00). */
    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        $this->flight('LB2', [0], '20:00');
        $this->flight('LB1', [1]);
        $this->period = $this->week();
    }

    public function test_rule_breaking_assignment_needs_a_reason_and_is_then_acknowledged(): void
    {
        $captain = $this->crew('CPT', 'Alpha Captain');
        $this->crew('CPT', 'Bravo Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('FO', 'Echo Officer');
        $this->crew('CC', 'Delta Cabin');
        $this->crew('CC', 'Foxtrot Cabin');
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/build")->assertOk()->assertJsonPath('data.summary.filled', 6);
        $evening = $this->seat('CPT', '2026-10-05');
        $morning = $this->seat('CPT', '2026-10-06');
        $this->putJson("/api/v1/assignments/{$evening->id}", ['crew_member_id' => $captain->id])->assertOk();

        // 7h50 between Monday's 23:10 release and Tuesday's 07:00 report is below the 12h minimum rest.
        $this->putJson("/api/v1/assignments/{$morning->id}", ['crew_member_id' => $captain->id])->assertUnprocessable()
            ->assertJsonValidationErrors(['crew_member_id', 'override_reason'])
            ->assertJsonPath('errors.crew_member_id.0', 'Only 7h50 rest after LB2 on 05 Oct (minimum 12h00).');
        $this->putJson("/api/v1/assignments/{$morning->id}", ['crew_member_id' => $captain->id, 'override_reason' => 'Ops approved reduced rest'])->assertOk()
            ->assertJsonPath('data.source', 'manual')->assertJsonPath('data.flag_reasons.0', 'Only 7h50 rest after LB2 on 05 Oct (minimum 12h00).')
            ->assertJsonPath('message', 'Alpha Captain assigned to LB1 on 06 Oct (captain) with a recorded override.');
        $this->assertDatabaseHas('audit_logs', ['entity' => 'assignments', 'entity_id' => $morning->id, 'action' => 'overridden']);

        // The overridden seat is acknowledged, but the evening seat it now clashes with still blocks publishing.
        $rest = collect($this->getJson("/api/v1/roster-periods/{$this->period->id}")->assertOk()->json('data.conflicts'))->where('code', 'rest')->keyBy('assignment_id');
        $this->assertTrue($rest[$morning->id]['acknowledged']);
        $this->assertFalse($rest[$morning->id]['blocking']);
        $this->assertTrue($rest[$evening->id]['blocking']);
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/publish")->assertUnprocessable()->assertJsonPath('errors.period.0', 'Resolve or override 1 rule conflict before publishing.');
    }

    public function test_candidates_list_legal_crew_first_with_reasons_for_the_rest(): void
    {
        $legal = $this->crew('CPT', 'Zulu Captain');
        $away = $this->crew('CPT', 'Alpha Captain', ['base_airport' => 'BLZ']);
        $this->actingAsScheduler();
        $this->postJson('/api/v1/roster-periods/'.$this->period->id.'/build')->assertOk();
        $seat = $this->seat('CPT', '2026-10-05');

        $candidates = $this->getJson("/api/v1/assignments/{$seat->id}/candidates")->assertOk()->json('data');
        $this->assertSame([$legal->id, $away->id], array_column($candidates, 'crew_member_id'));
        $this->assertTrue($candidates[0]['legal']);
        $this->assertSame('Based at BLZ; this trip starts at LLW.', $candidates[1]['issues'][0]['message']);
        $this->assertTrue($candidates[1]['overridable']);
    }

    public function test_position_mismatch_cannot_be_overridden_and_cleared_seats_return_to_the_generator(): void
    {
        $captain = $this->crew('CPT', 'Alpha Captain');
        $officer = $this->crew('FO', 'Charlie Officer');
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/build")->assertOk();
        $seat = $this->seat('CPT', '2026-10-05');

        $this->putJson("/api/v1/assignments/{$seat->id}", ['crew_member_id' => $officer->id, 'override_reason' => 'Short of captains'])->assertUnprocessable()
            ->assertJsonPath('errors.crew_member_id.0', 'Holds FO; this is a captain seat.');
        $this->assertDatabaseHas('assignments', ['id' => $seat->id, 'crew_member_id' => $captain->id]);

        $this->putJson("/api/v1/assignments/{$seat->id}", ['crew_member_id' => null])->assertOk()->assertJsonPath('data.crew_member_id', null)->assertJsonPath('data.source', 'auto');
    }

    public function test_excluded_crew_are_never_put_back_and_undo_restores_the_seat(): void
    {
        $captain = $this->crew('CPT', 'Alpha Captain');
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/build")->assertOk();
        $seat = $this->seat('CPT', '2026-10-05');

        $this->postJson("/api/v1/assignments/{$seat->id}/exclude")->assertOk()->assertJsonPath('data.crew_member_id', null)
            ->assertJsonPath('message', 'Alpha Captain will not be assigned to LB2 on 05 Oct again, and the seat is open.');
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/build")->assertOk();
        $this->assertNull($seat->fresh()->crew_member_id);
        $this->assertSame(1, $seat->fresh()->decision_log['reason_counts']['excluded']);

        // The rebuild replaced the manual log, so exclude again and undo straight away.
        $this->postJson("/api/v1/assignments/{$seat->id}/undo")->assertUnprocessable()->assertJsonPath('errors.assignment.0', 'There is no earlier change to undo for this seat.');
        $this->deleteJson('/api/v1/exclusions/'.$seat->trip->exclusions()->firstOrFail()->id)->assertOk();
        $this->putJson("/api/v1/assignments/{$seat->id}", ['crew_member_id' => $captain->id, 'override_reason' => 'Rest waiver approved'])->assertOk();
        $this->postJson("/api/v1/assignments/{$seat->id}/exclude")->assertOk();
        $this->postJson("/api/v1/assignments/{$seat->id}/undo")->assertOk()->assertJsonPath('data.crew_member_id', $captain->id)->assertJsonPath('data.source', 'manual');
        $this->assertDatabaseCount('exclusions', 0);
    }

    public function test_trips_that_have_operated_cannot_be_changed(): void
    {
        $captain = $this->crew('CPT', 'Alpha Captain');
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/build")->assertOk();
        $seat = $this->seat('CPT', '2026-10-05');
        $this->travelTo('2026-10-06 09:00:00');

        $this->putJson("/api/v1/assignments/{$seat->id}", ['crew_member_id' => null])->assertUnprocessable()
            ->assertJsonPath('errors.crew_member_id.0', 'This trip has already operated and can no longer be changed.');
        $this->assertDatabaseHas('assignments', ['id' => $seat->id, 'crew_member_id' => $captain->id]);
    }

    /** The seat of a position on the trip that starts on a date. */
    private function seat(string $rank, string $date): Assignment
    {
        return Assignment::query()->where('rank', $rank)->whereHas('trip', fn ($query) => $query->where('start_date', $date))->firstOrFail();
    }
}
