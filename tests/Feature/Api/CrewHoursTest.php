<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Accumulated hours: only published weeks count; each duty is "flown" once released and "scheduled" before
 * that; timed SIM counts as duty; pilots and cabin crew are reported separately; crew see their own hours.
 */
class CrewHoursTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    public function test_pilot_and_cabin_hours_accumulate_from_published_rosters_only(): void
    {
        $this->scenario();
        $this->flight('LB1', [0, 2]);
        $captain = $this->crew('CPT', 'Alpha Captain');
        $this->crew('FO', 'Charlie Officer');
        $cabin = $this->crew('CC', 'Delta Cabin');
        // SIM Tuesday 06:00–10:00 LT (04:00–08:00 UTC) counts as four hours of duty.
        $captain->activities()->create(['date' => '2026-10-06', 'type' => 'sim', 'starts_at' => '2026-10-06 04:00:00', 'ends_at' => '2026-10-06 08:00:00']);
        $published = $this->week();
        $draft = $this->week('2026-10-12');
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$published->id}/build")->assertOk();
        $this->postJson("/api/v1/roster-periods/{$published->id}/publish")->assertOk();
        $this->postJson("/api/v1/roster-periods/{$draft->id}/build")->assertOk();

        // Wednesday 10:00 LT: Monday's LB1 (2h block, 4h10 duty) has been flown; Wednesday's is still scheduled.
        $this->travelTo('2026-10-07 08:00:00');
        $pilots = $this->getJson('/api/v1/crew-hours')->assertOk()->assertJsonPath('data.group', 'pilots')->assertJsonCount(2, 'data.rows')->assertJsonPath('data.totals.week.trips', 4);
        $alpha = collect($pilots->json('data.rows'))->firstWhere('crew.id', $captain->id)['hours'];
        $this->assertSame(['block_minutes' => 120, 'duty_minutes' => 490, 'scheduled_block_minutes' => 120, 'scheduled_duty_minutes' => 250, 'trips' => 2], $alpha['week']);
        // The draft week 12–18 October adds nothing to October.
        $this->assertSame(120, $alpha['month']['scheduled_block_minutes']);

        $this->getJson('/api/v1/crew-hours?group=cabin')->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.crew.id', $cabin->id);
        $this->getJson('/api/v1/crew-hours?group=everyone')->assertUnprocessable();

        Sanctum::actingAs(User::factory()->create(['role' => 'crew', 'crew_member_id' => $cabin->id]), ['*']);
        $this->getJson('/api/v1/my-hours')->assertOk()->assertJsonPath('data.crew.id', $cabin->id)->assertJsonPath('data.hours.year.block_minutes', 120)->assertJsonPath('data.hours.year.trips', 2);
    }
}
