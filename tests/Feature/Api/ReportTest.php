<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\CrewMember;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Reports and PDF files: every report type as JSON, CSV and PDF; overrides with their reasons; hours from
 * published weeks; the roster PDF as a grid or per-crew pages, with crew limited to their own published page.
 */
class ReportTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    private const RANGE = '?from=2026-10-01&to=2026-10-31';

    /** LB2 Monday evening and LB1 Tuesday morning: too little rest to fly both, so crew cannot do both legally. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        $this->flight('LB2', [0], '20:00');
        $this->flight('LB1', [1]);
        $this->crew('CPT', 'Alpha Captain');
        $this->crew('CPT', 'Bravo Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
    }

    public function test_every_report_returns_json_csv_and_pdf(): void
    {
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonCount(7, 'data.types')->assertJsonPath('data.today', '2026-10-01');

        foreach (['coverage', 'hours', 'overrides', 'documents', 'maintenance', 'flights', 'audit'] as $type) {
            $this->getJson("/api/v1/reports/{$type}".self::RANGE)->assertOk()->assertJsonPath('data.type', $type)->assertJsonStructure(['data' => ['title', 'description', 'columns', 'rows', 'summary', 'period']]);
            $csv = $this->get("/api/v1/reports/{$type}/export.csv".self::RANGE)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
            $this->assertStringStartsWith("\u{FEFF}", $csv);
        }
        $this->getJson('/api/v1/reports/coverage'.self::RANGE)->assertJsonPath('data.rows.0.seats', 6)->assertJsonPath('data.rows.0.filled', 4)->assertJsonPath('data.rows.0.open', 2);
        $pdf = $this->get('/api/v1/reports/flights/export.pdf'.self::RANGE)->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_override_report_shows_the_rule_and_the_recorded_reason(): void
    {
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();
        $alpha = CrewMember::firstWhere('name', 'Alpha Captain');
        $tuesday = Assignment::query()->where('rank', 'CPT')->whereHas('trip', fn ($query) => $query->where('start_date', '2026-10-06'))->firstOrFail();
        $this->putJson("/api/v1/assignments/{$tuesday->id}", ['crew_member_id' => $alpha->id, 'override_reason' => 'Ops director approved'])->assertOk();

        $row = collect($this->getJson('/api/v1/reports/overrides'.self::RANGE)->assertOk()->json('data.rows'))->firstWhere('type', 'Override');
        $this->assertSame('Alpha Captain', $row['crew']);
        $this->assertSame('Ops director approved', $row['reason']);
        $this->assertStringContainsString('rest after LB2', $row['issues']);
    }

    public function test_report_filters_are_validated_and_crew_cannot_see_reports(): void
    {
        $this->actingAsScheduler();
        $this->getJson('/api/v1/reports/coverage?from=2026-01-01&to=2027-06-01')->assertUnprocessable()->assertJsonPath('errors.to.0', 'Choose a period of at most 366 days.');
        $this->getJson('/api/v1/reports/payroll'.self::RANGE)->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['role' => 'crew', 'crew_member_id' => CrewMember::first()->id]), ['*']);
        $this->getJson('/api/v1/reports/coverage'.self::RANGE)->assertForbidden();
        $this->get('/api/v1/reports/coverage/export.pdf'.self::RANGE, ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_roster_pdf_grid_and_crew_pages_with_crew_limited_to_their_own_published_page(): void
    {
        $period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$period->id}/build")->assertOk();

        $this->get("/api/v1/roster-periods/{$period->id}/roster.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDownload('roster-week-41-2026.pdf');
        $alpha = CrewMember::firstWhere('name', 'Alpha Captain');
        $this->get("/api/v1/roster-periods/{$period->id}/roster.pdf?layout=crew&crew_member_ids[]={$alpha->id}")->assertOk()->assertDownload('roster-week-41-2026-crew.pdf');

        Sanctum::actingAs(User::factory()->create(['role' => 'crew', 'crew_member_id' => $alpha->id]), ['*']);
        $this->get("/api/v1/roster-periods/{$period->id}/roster.pdf", ['Accept' => 'application/json'])->assertNotFound();
        $period->forceFill(['status' => 'published'])->save();
        $this->get("/api/v1/roster-periods/{$period->id}/roster.pdf?layout=grid")->assertOk()->assertDownload('roster-week-41-2026-crew.pdf');
    }
}
