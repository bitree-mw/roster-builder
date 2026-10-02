<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * The roster generator: expanding enabled patterns into a week's trips and seats, filling seats with legal
 * crew balanced by working hours, leaving seats open (with reasons) rather than breaking rules, keeping
 * manual seats on rebuild, and refusing published or past weeks.
 */
class RosterBuildTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
    }

    public function test_build_expands_enabled_patterns_and_balances_crew_by_hours_worked(): void
    {
        $this->flight('LB1', [0, 2]);
        $this->flight('LX9', [0], active: false);
        $alpha = $this->crew('CPT', 'Alpha Captain');
        $bravo = $this->crew('CPT', 'Bravo Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $period = $this->week();
        $this->actingAsScheduler();

        $response = $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()
            ->assertJsonPath('data.summary.trips', 2)->assertJsonPath('data.summary.seats', 6)
            ->assertJsonPath('data.summary.filled', 6)->assertJsonPath('data.summary.open', 0)->assertJsonPath('data.summary.conflicts', 0)
            ->assertJsonPath('message', 'Roster built for week 41 (05–11 Oct 2026): all 6 seats filled.');

        // Alpha takes Monday (alphabetical tie-break); Wednesday goes to Bravo, who has worked fewer hours.
        $captains = collect($response->json('data.trips'))->mapWithKeys(fn (array $trip): array => [$trip['start_date'] => collect($trip['assignments'])->firstWhere('rank', 'CPT')['crew_member_id']]);
        $this->assertSame([$alpha->id, $bravo->id], [$captains['2026-10-05'], $captains['2026-10-07']]);
        $this->assertSame('generator', Assignment::firstWhere('crew_member_id', $bravo->id)->decision_log['by']);
        $this->assertDatabaseHas('roster_periods', ['id' => $period->id, 'status' => 'draft']);
        $this->assertNotNull($period->fresh()->built_at);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'roster_periods', 'entity_id' => $period->id, 'action' => 'built']);
    }

    public function test_seats_stay_open_with_reasons_when_no_crew_member_is_legal(): void
    {
        $this->flight('LB1', [0]);
        $this->crew('CPT', 'Expired Captain')->documents()->where('kind', 'medical')->update(['expires_on' => '2026-10-04']);
        $this->crew('CPT', 'Leave Captain')->activities()->create(['date' => '2026-10-05', 'type' => 'leave']);
        $this->crew('CPT', 'Blantyre Captain', ['base_airport' => 'BLZ']);
        $this->crew('CPT', 'Unrated Captain')->ratings()->detach();
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $period = $this->week();
        $this->actingAsScheduler();

        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()
            ->assertJsonPath('data.summary.filled', 2)->assertJsonPath('data.summary.open', 1);
        $seat = Assignment::firstWhere('rank', 'CPT');
        $this->assertNull($seat->crew_member_id);
        $this->assertSame(['base', 'document_expired', 'rating', 'unavailable'], collect($seat->decision_log['reason_counts'])->keys()->sort()->values()->all());
        $this->assertSame(0, $seat->decision_log['legal']);
    }

    public function test_generator_never_exceeds_a_crew_members_weekly_working_hours(): void
    {
        $this->flight('LB1', [0, 1, 2]);
        $partTimer = $this->crew('CPT', 'Part Timer', ['weekly_hours' => 5]);
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $period = $this->week();
        $this->actingAsScheduler();

        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()->assertJsonPath('data.summary.open', 2);
        $this->assertSame(1, Assignment::where('crew_member_id', $partTimer->id)->count());
        $open = Assignment::where('rank', 'CPT')->whereNull('crew_member_id')->get();
        $this->assertTrue($open->every(fn (Assignment $seat): bool => isset($seat->decision_log['reason_counts']['weekly_hours'])));
    }

    public function test_rebuild_keeps_manual_seats_and_refills_released_ones(): void
    {
        $this->flight('LB1', [0, 2]);
        $this->crew('CPT', 'Alpha Captain');
        $bravo = $this->crew('CPT', 'Bravo Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $monday = Assignment::query()->where('rank', 'CPT')->whereHas('trip', fn ($query) => $query->where('start_date', '2026-10-05'))->firstOrFail();

        $this->putJson("/api/v1/assignments/{$monday->id}", ['crew_member_id' => $bravo->id])->assertOk()->assertJsonPath('data.source', 'manual');
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()->assertJsonPath('data.summary.build.kept', 1)->assertJsonPath('data.summary.filled', 6);

        $this->assertDatabaseHas('assignments', ['id' => $monday->id, 'crew_member_id' => $bravo->id, 'source' => 'manual']);
        $this->assertDatabaseCount('trips', 2);
    }

    public function test_standby_fills_workload_gaps_up_to_the_weekly_days_off_limit_and_is_replaced_on_rebuild(): void
    {
        $idle = $this->crew('CPT', 'Idle Captain');
        $onLeave = $this->crew('CPT', 'Leave Captain');
        foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $date) {
            $onLeave->activities()->create(['date' => $date, 'type' => 'leave']);
        }
        $period = $this->week();
        $this->actingAsScheduler();

        // 7 free days, at most 4 off: 3 standby days. Leave Captain: 2 available days, round(4 × 2 / 7) = 1 off allowed, so 1 standby.
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()->assertJsonPath('data.summary.build.standby', 4);
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07'], $idle->activities()->where('type', 'standby')->orderBy('date')->get()->map(fn ($activity): string => $activity->date->format('Y-m-d'))->all());
        $this->assertSame(1, $onLeave->activities()->where('type', 'standby')->count());
        $standby = $idle->activities()->where('type', 'standby')->first();
        $this->assertSame('2026-10-05 04:00:00', $standby->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($period->id, $standby->roster_period_id);

        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $this->assertSame(3, $idle->activities()->where('type', 'standby')->count());
    }

    public function test_rebalancing_moves_a_seat_to_the_captain_with_more_working_hours_left(): void
    {
        // Alpha flies Thursday to Sunday of the current week, so the fairness score (month block hours)
        // first gives both of next week's trips to Bravo; rebalancing then evens out the weekly hours.
        $history = $this->flight('LH1', [3, 4, 5, 6]);
        $this->flight('LB1', [0, 2]);
        $alpha = $this->crew('CPT', 'Alpha Captain');
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$this->week('2026-09-28')->id}/build")->assertOk();
        $bravo = $this->crew('CPT', 'Bravo Captain');
        $history->update(['active' => false]);
        $period = $this->week();

        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk()->assertJsonPath('data.summary.build.rebalanced', 1);
        $seats = Assignment::query()->where('rank', 'CPT')->whereHas('trip', fn ($query) => $query->where('roster_period_id', $period->id))->get();
        $this->assertEqualsCanonicalizing([$alpha->id, $bravo->id], $seats->pluck('crew_member_id')->all());
        $this->assertSame('Bravo Captain', $seats->firstWhere('crew_member_id', $alpha->id)->decision_log['rebalanced_from']['name']);
    }

    public function test_published_past_and_crew_builds_are_refused(): void
    {
        $this->flight('LB1', [0]);
        $published = $this->week('2026-10-05', 'published');
        $past = $this->week('2026-09-21');
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$published->id}/build")->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->postJson("/api/v1/roster-periods/{$past->id}/build")->assertUnprocessable()->assertJsonPath('errors.period.0', 'This week has ended. Past rosters are kept as read-only history.');

        Sanctum::actingAs(User::factory()->create(['role' => 'crew']), ['*']);
        $this->postJson("/api/v1/roster-periods/{$this->week('2026-10-12')->id}/build")->assertForbidden();
        $this->assertDatabaseCount('trips', 0);
    }
}
