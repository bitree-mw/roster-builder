<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Flight;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Keeping built rosters in step with the Flight routes page: disabling, enabling or editing a flight lists
 * the trips a rebuild would remove, add or change; the rebuild only runs once that exact list is confirmed;
 * the confirmed rebuild removes trips of disabled flights even when seats on them are locked; the timeline
 * flags rosters with pending changes and crew never see them.
 */
class RosterRouteChangesTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
    }

    public function test_disabled_flight_is_removed_only_by_a_confirmed_rebuild_including_locked_seats(): void
    {
        $shuttle = $this->flight('LB1', [0, 2]);
        $this->flight('LB2', [1]);
        $this->crew('CPT', 'Alpha Captain');
        $bravo = $this->crew('CPT', 'Bravo Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $monday = Assignment::query()->where('rank', 'CPT')->whereHas('trip', fn ($query) => $query->where('start_date', '2026-10-05'))->firstOrFail();
        $this->putJson("/api/v1/assignments/{$monday->id}", ['crew_member_id' => $bravo->id])->assertOk();

        $this->patchJson("/api/v1/flights/{$shuttle->id}/status", ['active' => false])->assertOk()
            ->assertJsonPath('message', 'Flight LB1 disabled. Rosters still including it: Week 41 (05–11 Oct 2026). Open them on the Roster page to review and confirm its removal.');

        $changes = $this->getJson("/api/v1/roster-periods/{$period->id}")->assertOk()
            ->assertJsonPath('data.route_changes.count', 2)->assertJsonPath('data.route_changes.added', [])->assertJsonPath('data.route_changes.changed', [])
            ->assertJsonPath('data.route_changes.released_seats', 6)->assertJsonPath('data.route_changes.locked_seats', 1)
            ->assertJsonPath('data.route_changes.removed.0.date', '2026-10-05')->assertJsonPath('data.route_changes.removed.0.reason', 'disabled')
            ->assertJsonPath('data.route_changes.removed.0.seats.0', ['rank' => 'CPT', 'seat_number' => 1, 'name' => 'Bravo Captain', 'locked' => true, 'section' => null])
            ->assertJsonPath('data.route_changes.removed.1.date', '2026-10-07')
            ->json('data.route_changes');

        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertUnprocessable()
            ->assertJsonPath('errors.route_changes.0', 'Flight routes have changed since this roster was built. Review the route changes and confirm them to rebuild.');
        $this->assertDatabaseCount('trips', 3);

        $this->postJson("/api/v1/roster-periods/{$period->id}/build", ['route_changes' => $changes['fingerprint']])->assertOk()
            ->assertJsonPath('data.summary.build.route_changes', ['added' => 0, 'removed' => 2, 'changed' => 0, 'released_seats' => 6])
            ->assertJsonPath('data.route_changes.count', 0)->assertJsonPath('data.trips.0.code', 'LB2');
        $this->assertDatabaseCount('trips', 1);
        $this->assertModelMissing($monday);
        $audit = AuditLog::query()->where('entity', 'roster_periods')->where('action', 'built')->latest('id')->firstOrFail();
        $this->assertSame('Bravo Captain', $audit->after['route_changes']['removed'][0]['seats'][0]['name']);
    }

    public function test_enabled_and_retimed_flights_are_listed_and_a_stale_confirmation_is_refused(): void
    {
        $shuttle = $this->flight('LB1', [0]);
        $evening = $this->flight('LB2', [2], active: false);
        $this->crew('CPT', 'Alpha Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $shuttle->legs()->where('sequence', 1)->update(['departs_local' => '09:00', 'arrives_local' => '10:00']);
        $shuttle->legs()->where('sequence', 2)->update(['departs_local' => '10:40', 'arrives_local' => '11:40']);
        $reviewed = $this->getJson("/api/v1/roster-periods/{$period->id}")->json('data.route_changes.fingerprint');

        $this->patchJson("/api/v1/flights/{$evening->id}/status", ['active' => true])->assertOk()
            ->assertJsonPath('message', 'Flight LB2 enabled. Rosters that do not include it yet: Week 41 (05–11 Oct 2026). Open them on the Roster page to review and confirm the changes.');

        $changes = $this->getJson("/api/v1/roster-periods/{$period->id}")->assertOk()
            ->assertJsonPath('data.route_changes.count', 2)
            ->assertJsonPath('data.route_changes.added', [['flight_id' => $evening->id, 'code' => 'LB2', 'date' => '2026-10-07', 'route' => 'LLW-BLZ-LLW']])
            ->assertJsonPath('data.route_changes.changed.0.fields', ['times'])
            ->assertJsonPath('data.route_changes.changed.0.details', ['Duty 08:00–12:10 LT (was 07:00–11:10)'])
            ->json('data.route_changes');
        $this->assertNotSame($reviewed, $changes['fingerprint']);
        $this->postJson("/api/v1/roster-periods/{$period->id}/build", ['route_changes' => $reviewed])->assertUnprocessable()->assertJsonValidationErrors('route_changes');

        $this->postJson("/api/v1/roster-periods/{$period->id}/build", ['route_changes' => $changes['fingerprint']])->assertOk()
            ->assertJsonPath('data.summary.build.route_changes', ['added' => 1, 'removed' => 0, 'changed' => 1, 'released_seats' => 0])
            ->assertJsonPath('data.trips.0.duties.0.report_local', '08:00')->assertJsonPath('data.route_changes.count', 0);
        $this->assertDatabaseCount('trips', 2);
    }

    public function test_timeline_flags_rosters_with_route_changes_and_crew_never_see_them(): void
    {
        $shuttle = $this->flight('LB1', [0]);
        $captain = $this->crew('CPT', 'Alpha Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $published = $this->week();
        $unbuilt = $this->week('2026-10-12');
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$published->id}/build")->assertOk();
        $this->postJson("/api/v1/roster-periods/{$published->id}/publish")->assertOk();

        $this->patchJson("/api/v1/flights/{$shuttle->id}/status", ['active' => false])->assertOk();

        $this->getJson('/api/v1/roster-periods?from=2026-10-05&to=2026-10-18')->assertOk()
            ->assertJsonPath('data.0.route_changes_count', 1)->assertJsonPath('data.1.route_changes_count', 0);
        $this->getJson("/api/v1/roster-periods/{$published->id}")->assertJsonPath('data.route_changes.removed.0.reason', 'disabled');
        $this->getJson("/api/v1/roster-periods/{$unbuilt->id}")->assertJsonPath('data.route_changes', null);
        Sanctum::actingAs(User::factory()->create(['role' => 'crew', 'crew_member_id' => $captain->id]), ['*']);
        $this->getJson("/api/v1/roster-periods/{$published->id}")->assertOk()->assertJsonPath('data.route_changes', null);
        $this->getJson('/api/v1/roster-periods?from=2026-10-05&to=2026-10-18')->assertOk()->assertJsonMissingPath('data.0.route_changes_count');
    }

    public function test_malformed_route_change_confirmation_is_rejected_with_422(): void
    {
        $period = $this->week();
        $this->actingAsScheduler();

        $this->postJson("/api/v1/roster-periods/{$period->id}/build", ['route_changes' => 'yes'])->assertUnprocessable()
            ->assertJsonPath('errors.route_changes.0', 'The route changes field format is invalid.');
        $this->assertNull($period->fresh()->built_at);
    }

    public function test_flight_messages_stay_short_when_no_built_roster_is_affected(): void
    {
        $shuttle = $this->flight('LB1', [0]);
        $this->week();
        $this->actingAsScheduler();

        $this->patchJson("/api/v1/flights/{$shuttle->id}/status", ['active' => false])->assertOk()
            ->assertJsonPath('message', 'Flight LB1 disabled — it stays on file but will not be planned.');
        $this->assertFalse(Flight::findOrFail($shuttle->id)->active);
    }
}
