<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/** Exchanges preserve both holders unless both resulting duties pass legality and access checks. */
class AssignmentExchangeTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    /** A swap resolves the rest conflict caused by adding a flight without releasing the old duty. */
    #[TestWith(['CC'])]
    #[TestWith(['CPT'])]
    #[TestWith(['FO'])]
    public function test_same_position_crew_can_exchange_duties_atomically(string $rank): void
    {
        [$seat, $other] = $this->pair($rank);
        $candidate = collect($this->getJson("/api/v1/assignments/{$seat->id}/candidates")->assertOk()->json('data'))->firstWhere('crew_member_id', $other->crew_member_id);
        $this->assertFalse($candidate['legal']);
        $this->assertSame([$other->id], array_column($candidate['exchanges'], 'assignment_id'));

        $this->putJson("/api/v1/assignments/{$seat->id}", $this->payload($seat, $other))
            ->assertOk()->assertJsonPath('data.crew_member_id', $other->crew_member_id);

        $this->assertDatabaseHas('assignments', ['id' => $seat->id, 'crew_member_id' => $other->crew_member_id, 'source' => 'manual']);
        $this->assertDatabaseHas('assignments', ['id' => $other->id, 'crew_member_id' => $seat->crew_member_id, 'source' => 'manual']);
        foreach ([$seat, $other] as $changed) {
            $this->assertDatabaseHas('audit_logs', ['entity' => 'assignments', 'entity_id' => $changed->id, 'action' => 'exchanged']);
        }
        $this->postJson("/api/v1/assignments/{$seat->id}/undo")->assertUnprocessable();
    }

    /** Reciprocal leave rejects the swap even when the incoming crew could otherwise take the source. */
    public function test_an_illegal_reciprocal_duty_rejects_both_changes(): void
    {
        [$seat, $other] = $this->pair('CPT');
        $seat->crewMember->activities()->create(['date' => '2026-10-06', 'type' => 'leave']);
        $beforeAudit = AuditLog::count();
        $candidate = collect($this->getJson("/api/v1/assignments/{$seat->id}/candidates")->assertOk()->json('data'))->firstWhere('crew_member_id', $other->crew_member_id);
        $this->assertSame([], $candidate['exchanges']);

        $this->putJson("/api/v1/assignments/{$seat->id}", [...$this->payload($seat, $other), 'override_reason' => 'Cannot bypass exchange checks'])
            ->assertUnprocessable()->assertJsonValidationErrors('exchange_assignment_id');

        $this->assertDatabaseHas('assignments', ['id' => $seat->id, 'crew_member_id' => $seat->crew_member_id]);
        $this->assertDatabaseHas('assignments', ['id' => $other->id, 'crew_member_id' => $other->crew_member_id]);
        $this->assertSame($beforeAudit, AuditLog::count());
    }

    /** Invalid or stale exchange instructions must never displace either holder. */
    #[TestWith(['stale'])]
    #[TestWith(['operated'])]
    #[TestWith(['position'])]
    #[TestWith(['ambiguous'])]
    public function test_invalid_exchanges_leave_both_holders_unchanged(string $failure): void
    {
        [$seat, $other] = $this->pair('FO');
        $payload = $this->payload($seat, $other);
        if ($failure === 'stale') {
            $payload['expected_crew_member_id'] = $other->crew_member_id;
        } elseif ($failure === 'operated') {
            $this->travelTo('2026-10-06 08:00:00');
            [$seat, $other] = [$other, $seat];
            $payload = $this->payload($seat, $other);
        } elseif ($failure === 'position') {
            $other->forceFill(['rank' => 'CC', 'seat_number' => 99])->save();
        } else {
            $payload['target_assignment_id'] = $other->id;
        }

        $this->putJson("/api/v1/assignments/{$seat->id}", $payload)->assertUnprocessable()->assertJsonValidationErrors('exchange_assignment_id');

        $this->assertDatabaseHas('assignments', ['id' => $seat->id, 'crew_member_id' => $seat->crew_member_id]);
        $this->assertDatabaseHas('assignments', ['id' => $other->id, 'crew_member_id' => $other->crew_member_id]);
    }

    /** The existing write boundary also protects both sides of an exchange from crew accounts. */
    public function test_crew_cannot_exchange_duties(): void
    {
        [$seat, $other] = $this->pair('CC');
        Sanctum::actingAs(User::factory()->create(['role' => 'crew']), ['*']);

        $this->putJson("/api/v1/assignments/{$seat->id}", $this->payload($seat, $other))->assertForbidden();

        $this->assertDatabaseHas('assignments', ['id' => $seat->id, 'crew_member_id' => $seat->crew_member_id]);
    }

    /** Exchanging captains changes holders without extending a captain section to the whole rotation. */
    public function test_captain_exchanges_preserve_each_seats_leg_boundaries(): void
    {
        [$seat, $other] = $this->pair('CPT');
        $this->postJson("/api/v1/assignments/{$seat->id}/split", ['after_leg' => 1])->assertOk();

        $this->putJson("/api/v1/assignments/{$seat->id}", $this->payload($seat, $other))->assertOk();

        $this->assertDatabaseHas('assignments', ['id' => $seat->id, 'crew_member_id' => $other->crew_member_id, 'from_leg' => 1, 'to_leg' => 1]);
        $this->assertDatabaseHas('assignments', ['id' => $other->id, 'crew_member_id' => $seat->crew_member_id, 'from_leg' => null, 'to_leg' => null]);
    }

    /**
     * Two flights with insufficient rest if combined, legal when separately held. Clear generated standby
     * from this fixture to isolate reciprocal flight checks from the workload-balancing rule.
     *
     * @return array{Assignment, Assignment}
     */
    private function pair(string $rank): array
    {
        $this->scenario();
        $this->flight('EVENING', [0], '20:00');
        $this->flight('MORNING', [1]);
        $alpha = $this->crew($rank, 'Alpha Crew');
        $bravo = $this->crew($rank, 'Bravo Crew');
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $alpha->activities()->delete();
        $bravo->activities()->delete();
        $seats = Assignment::query()->where('rank', $rank)->orderBy('trip_id')->get();
        foreach ($seats as $seat) {
            $this->putJson("/api/v1/assignments/{$seat->id}", ['crew_member_id' => null])->assertOk();
        }
        $this->putJson("/api/v1/assignments/{$seats[0]->id}", ['crew_member_id' => $alpha->id])->assertOk();
        $this->putJson("/api/v1/assignments/{$seats[1]->id}", ['crew_member_id' => $bravo->id])->assertOk();

        return [$seats[0]->fresh(), $seats[1]->fresh()];
    }

    /** @return array{crew_member_id: int, exchange_assignment_id: int, expected_crew_member_id: int} Reviewed exchange holders. */
    private function payload(Assignment $seat, Assignment $other): array
    {
        return ['crew_member_id' => $other->crew_member_id, 'exchange_assignment_id' => $other->id, 'expected_crew_member_id' => $seat->crew_member_id];
    }
}
