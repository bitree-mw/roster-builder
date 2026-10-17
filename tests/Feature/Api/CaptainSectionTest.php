<?php

namespace Tests\Feature\Api;

use App\Mail\RosterMail;
use App\Models\Airport;
use App\Models\Assignment;
use App\Models\Flight;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Captain sections: a captain seat can be split at any stop so captains fly only part of a pattern, while
 * first officers and cabin crew fly all of it. Each section is planned and checked as its own duty; a section
 * away from the captain's base is a positioning warning (never auto-planned); pattern changes keep or reset
 * the sections through the confirmed route-change review; and a republished roster records the crew whose
 * duties changed so only they are emailed.
 */
class CaptainSectionTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        Airport::factory()->create(['code' => 'MZU', 'is_base' => false]);
    }

    public function test_a_captain_seat_splits_at_a_stop_and_each_section_is_planned_as_its_own_duty(): void
    {
        $this->doubleLoop();
        $this->crew('CPT', 'Alpha Captain');
        $this->crew('CPT', 'Bravo Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $captain = Assignment::firstWhere('rank', 'CPT');

        $this->postJson("/api/v1/assignments/{$captain->id}/split", ['after_leg' => 2])->assertOk()
            ->assertJsonPath('message', 'Captain on LB5 on 05 Oct now changes at LLW: LLW-BLZ-LLW, then LLW-MZU-LLW. The new captain section is open.')
            ->assertJsonPath('data.from_leg', 1)->assertJsonPath('data.to_leg', 2)->assertJsonPath('data.crew_member_id', $captain->crew_member_id);
        $this->assertDatabaseHas('assignments', ['rank' => 'CPT', 'seat_number' => 2, 'from_leg' => 3, 'to_leg' => 4, 'crew_member_id' => null]);

        $seats = $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()->assertJsonPath('data.summary.filled', 4)->json('data.trips.0.assignments');
        $this->assertSame(['LLW-BLZ-LLW', '07:00', '11:10'], [$seats[0]['section']['route'], $seats[0]['section']['duties'][0]['report_local'], $seats[0]['section']['duties'][0]['release_local']]);
        $this->assertSame(['LLW-MZU-LLW', '10:30', '14:40'], [$seats[1]['section']['route'], $seats[1]['section']['duties'][0]['report_local'], $seats[1]['section']['duties'][0]['release_local']]);
        $this->assertNotSame($seats[0]['crew_member_id'], $seats[1]['crew_member_id']);
        $this->assertSame(['FO', null], [$seats[2]['rank'], $seats[2]['section']]);
    }

    public function test_only_captain_seats_split_and_only_at_a_stop_inside_the_section_with_422(): void
    {
        $this->doubleLoop();
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $officer = Assignment::firstWhere('rank', 'FO');
        $captain = Assignment::firstWhere('rank', 'CPT');

        $this->postJson("/api/v1/assignments/{$officer->id}/split", ['after_leg' => 2])->assertUnprocessable()
            ->assertJsonPath('errors.after_leg.0', 'Only captain seats can be split. First officers and cabin crew fly the whole trip.');
        $this->postJson("/api/v1/assignments/{$captain->id}/split", ['after_leg' => 4])->assertUnprocessable()
            ->assertJsonPath('errors.after_leg.0', 'Choose a stop inside this captain\'s section, before its last leg.');
        $this->postJson("/api/v1/assignments/{$captain->id}/split", [])->assertUnprocessable()->assertJsonValidationErrors('after_leg');
        $this->assertSame(1, Assignment::where('rank', 'CPT')->count());
    }

    public function test_a_section_starting_away_from_base_is_a_positioning_warning_and_never_planned_automatically(): void
    {
        $this->doubleLoop();
        $this->crew('CPT', 'Alpha Captain');
        $bravo = $this->crew('CPT', 'Bravo Captain');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $this->postJson('/api/v1/assignments/'.Assignment::firstWhere('rank', 'CPT')->id.'/split', ['after_leg' => 1])->assertOk();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $section = Assignment::query()->where('rank', 'CPT')->where('seat_number', 2)->sole();
        $this->assertNull($section->crew_member_id);
        $this->assertSame(2, $section->decision_log['reason_counts']['positioning']);

        $candidate = collect($this->getJson("/api/v1/assignments/{$section->id}/candidates")->assertOk()->json('data'))->firstWhere('crew_member_id', $bravo->id);
        $this->assertTrue($candidate['legal']);
        $this->assertSame('Based at LLW but would join at BLZ: positioning needed.', $candidate['warnings'][0]['message']);
        $this->putJson("/api/v1/assignments/{$section->id}", ['crew_member_id' => $bravo->id])->assertOk()->assertJsonPath('data.source', 'manual');

        $week = $this->getJson("/api/v1/roster-periods/{$period->id}")->assertOk()->assertJsonPath('data.summary.blocking', 0);
        $this->assertSame([['severity' => 'warning', 'code' => 'positioning', 'blocking' => false]], collect($week->json('data.conflicts'))->where('code', 'positioning')->map(fn (array $conflict): array => collect($conflict)->only(['code', 'severity', 'blocking'])->all())->values()->all());
    }

    public function test_pattern_changes_keep_sections_when_only_times_move_and_reset_them_when_the_legs_change(): void
    {
        $flight = $this->doubleLoop();
        $this->crew('CPT', 'Alpha Captain');
        $this->crew('CPT', 'Bravo Captain');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $this->postJson('/api/v1/assignments/'.Assignment::firstWhere('rank', 'CPT')->id.'/split', ['after_leg' => 2])->assertOk();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        foreach ($flight->legs as $leg) {
            $leg->update(['departs_local' => date('H:i', strtotime($leg->departs_local) + 3600), 'arrives_local' => date('H:i', strtotime($leg->arrives_local) + 3600)]);
        }
        $retimed = $this->getJson("/api/v1/roster-periods/{$period->id}")->assertJsonPath('data.route_changes.changed.0.fields', ['times'])->json('data.route_changes.fingerprint');
        $this->postJson("/api/v1/roster-periods/{$period->id}/build", ['route_changes' => $retimed])->assertOk();
        $this->assertSame(2, Assignment::where('rank', 'CPT')->count());

        $flight->legs()->whereIn('sequence', [3, 4])->delete();

        $changes = $this->getJson("/api/v1/roster-periods/{$period->id}")->assertJsonPath('data.route_changes.changed.0.fields', ['route', 'sections', 'times'])->json('data.route_changes');
        $this->assertSame('Captain sections joined back into one seat; releases Alpha Captain (LLW-BLZ-LLW), Bravo Captain (LLW-MZU-LLW)', $changes['changed'][0]['details'][1]);
        $this->postJson("/api/v1/roster-periods/{$period->id}/build", ['route_changes' => $changes['fingerprint']])->assertOk()->assertJsonPath('data.summary.filled', 1);
        $this->assertDatabaseHas('assignments', ['rank' => 'CPT', 'seat_number' => 1, 'from_leg' => null, 'to_leg' => null]);
        $this->assertSame(1, Assignment::where('rank', 'CPT')->count());
    }

    public function test_joining_sections_releases_both_captains_and_opens_the_whole_trip_seat(): void
    {
        $this->doubleLoop();
        $this->crew('CPT', 'Alpha Captain');
        $this->crew('CPT', 'Bravo Captain');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $first = Assignment::firstWhere('rank', 'CPT');
        $this->postJson("/api/v1/assignments/{$first->id}/split", ['after_leg' => 2])->assertOk();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();

        $this->postJson("/api/v1/assignments/{$first->id}/join")->assertOk()
            ->assertJsonPath('message', 'Captain sections on LB5 on 05 Oct joined and Alpha Captain and Bravo Captain released. The captain seat is open: assign a captain or rebuild.');

        $this->assertDatabaseHas('assignments', ['id' => $first->id, 'from_leg' => null, 'to_leg' => null, 'crew_member_id' => null]);
        $this->assertSame(1, Assignment::where('rank', 'CPT')->count());
        $this->assertDatabaseHas('audit_logs', ['entity' => 'assignments', 'entity_id' => $first->id, 'action' => 'joined']);
    }

    public function test_crew_flying_a_section_see_only_that_section(): void
    {
        $this->doubleLoop();
        $this->crew('CPT', 'Alpha Captain');
        $bravo = $this->crew('CPT', 'Bravo Captain');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $this->postJson('/api/v1/assignments/'.Assignment::firstWhere('rank', 'CPT')->id.'/split', ['after_leg' => 2])->assertOk();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $this->postJson("/api/v1/roster-periods/{$period->id}/publish")->assertOk();
        Sanctum::actingAs(User::factory()->create(['role' => 'crew', 'crew_member_id' => $bravo->id]), ['*']);

        $this->getJson("/api/v1/roster-periods/{$period->id}")->assertOk()
            ->assertJsonPath('data.trips.0.route', 'LLW-MZU-LLW')->assertJsonPath('data.trips.0.duties.0.report_local', '10:30')
            ->assertJsonCount(1, 'data.trips.0.assignments')->assertJsonPath('data.trips.0.legs', [])->assertJsonPath('data.changed_crew', null);
    }

    public function test_republishing_after_a_flight_change_records_changed_crew_and_emails_only_them(): void
    {
        $this->flight('LB1', [0]);
        $midweek = $this->flight('LB2', [2]);
        $alpha = $this->crew('CPT', 'Alpha Captain');
        $bravo = $this->crew('CPT', 'Bravo Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $this->postJson("/api/v1/roster-periods/{$period->id}/publish")->assertOk()->assertJsonPath('data.changed_crew', null);
        $this->postJson("/api/v1/roster-periods/{$period->id}/email", ['only' => 'changed'])->assertUnprocessable()
            ->assertJsonPath('errors.only.0', 'No crew have different duties since this roster was last published.');
        $this->postJson("/api/v1/roster-periods/{$period->id}/reopen")->assertOk();
        $this->patchJson("/api/v1/flights/{$midweek->id}/status", ['active' => false])->assertOk();
        $fingerprint = $this->getJson("/api/v1/roster-periods/{$period->id}")->json('data.route_changes.fingerprint');
        $this->postJson("/api/v1/roster-periods/{$period->id}/build", ['route_changes' => $fingerprint])->assertOk();

        $changed = $this->postJson("/api/v1/roster-periods/{$period->id}/publish")->assertOk()
            ->assertJsonPath('message', 'Roster for week 41 (05–11 Oct 2026) published again. 3 crew have different duties since it was last published.')
            ->json('data.changed_crew');
        $this->assertSame(['Bravo Captain', 'Charlie Officer', 'Delta Cabin'], array_column($changed, 'name'));
        $this->assertSame(['LB2 07 Oct LLW-BLZ-LLW 07:00–11:10 LT, CPT'], $changed[0]['removed']);

        Mail::fake();
        $this->postJson("/api/v1/roster-periods/{$period->id}/email", ['only' => 'changed'])->assertOk()->assertJsonPath('data.sent', 3);
        Mail::assertSent(RosterMail::class, 3);
        Mail::assertNotSent(RosterMail::class, fn (RosterMail $mail): bool => $mail->hasTo($alpha->email));
        Mail::assertSent(RosterMail::class, function (RosterMail $mail) use ($bravo): bool {
            if (! $mail->hasTo($bravo->email)) {
                return false;
            }
            $mail->assertSeeInHtml('Removed: LB2 07 Oct LLW-BLZ-LLW 07:00–11:10 LT, CPT', false);

            return $mail->envelope()->subject === 'Roster changed: Week 41 (05–11 Oct 2026)';
        });
    }

    /**
     * LB5 on Mondays: LLW-BLZ-LLW then LLW-MZU-LLW on one day (base-local 08:00–14:10, duty 07:00–14:40).
     */
    private function doubleLoop(): Flight
    {
        $flight = Flight::factory()->create(['code' => 'LB5', 'aircraft_type_id' => $this->q400->id]);
        $flight->days()->create(['weekday' => 0]);
        $flight->legs()->createMany([
            ['trip_day' => 1, 'sequence' => 1, 'from_airport' => 'LLW', 'to_airport' => 'BLZ', 'departs_local' => '08:00', 'arrives_local' => '09:00'],
            ['trip_day' => 1, 'sequence' => 2, 'from_airport' => 'BLZ', 'to_airport' => 'LLW', 'departs_local' => '09:40', 'arrives_local' => '10:40'],
            ['trip_day' => 1, 'sequence' => 3, 'from_airport' => 'LLW', 'to_airport' => 'MZU', 'departs_local' => '11:30', 'arrives_local' => '12:30'],
            ['trip_day' => 1, 'sequence' => 4, 'from_airport' => 'MZU', 'to_airport' => 'LLW', 'departs_local' => '13:10', 'arrives_local' => '14:10'],
        ]);

        return $flight;
    }
}
