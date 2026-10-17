<?php

namespace Tests\Feature\Api;

use App\Mail\RosterMail;
use App\Models\Assignment;
use App\Models\RosterPeriod;
use App\Models\Trip;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Changing a roster after it was published: seats can still be changed by hand (the roster stays published and
 * crew see it at once), the crew whose duties now differ from what they were told are listed, and emailing them
 * (or everyone) brings them up to date. Builds still need a draft, and rosters that have ended cannot change.
 */
class PublishedRosterChangesTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
    }

    public function test_a_seat_change_on_a_published_roster_lists_the_crew_to_alert_until_they_are_emailed(): void
    {
        $this->flight('LB1', [0]);
        $this->crew('CPT', 'Alpha Captain');
        $bravo = $this->crew('CPT', 'Bravo Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $this->postJson("/api/v1/roster-periods/{$period->id}/publish")->assertOk();
        $seat = Assignment::firstWhere('rank', 'CPT');

        $this->putJson("/api/v1/assignments/{$seat->id}", ['crew_member_id' => $bravo->id])->assertOk();

        $this->assertDatabaseHas('roster_periods', ['id' => $period->id, 'status' => 'published']);
        $changed = $this->getJson("/api/v1/roster-periods/{$period->id}")->assertOk()->json('data.changed_crew');
        $this->assertSame([['Alpha Captain', ['LB1 05 Oct LLW-BLZ-LLW 07:00–11:10 LT, CPT'], []], ['Bravo Captain', [], ['LB1 05 Oct LLW-BLZ-LLW 07:00–11:10 LT, CPT']]],
            array_map(fn (array $crew): array => [$crew['name'], $crew['removed'], $crew['added']], $changed));

        Mail::fake();
        $this->postJson("/api/v1/roster-periods/{$period->id}/email", ['only' => 'changed'])->assertOk()->assertJsonPath('data.sent', 2);
        Mail::assertSent(RosterMail::class, 2);
        $this->getJson("/api/v1/roster-periods/{$period->id}")->assertJsonPath('data.changed_crew', []);
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_a_roster_that_has_ended_cannot_be_changed_with_422(): void
    {
        $flight = $this->flight('LB1', [0]);
        $past = RosterPeriod::factory()->create(['starts_on' => '2026-09-21', 'ends_on' => '2026-09-27', 'status' => 'published', 'rules_snapshot' => $this->week('2026-10-12')->rules_snapshot]);
        $trip = Trip::factory()->create(['roster_period_id' => $past->id, 'flight_id' => $flight->id, 'start_date' => '2026-09-21']);
        $seat = $trip->assignments()->create(['rank' => 'CPT', 'seat_number' => 1, 'source' => 'auto']);
        $captain = $this->crew('CPT', 'Alpha Captain');
        $this->actingAsScheduler();

        $this->putJson("/api/v1/assignments/{$seat->id}", ['crew_member_id' => $captain->id])->assertUnprocessable()
            ->assertJsonPath('errors.period.0', 'This roster has ended. Past rosters are kept as read-only history.');
        $this->assertDatabaseHas('assignments', ['id' => $seat->id, 'crew_member_id' => null]);
    }
}
