<?php

namespace Tests\Feature\Api;

use App\Models\CrewActivity;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * CSV import: header aliases, semicolon files and Excel dates; every row previewed before anything is
 * saved; errors and stale previews block the commit; replace mode deactivates or disables what is missing;
 * flights validated like the editor; day planning with ranges and times; templates.
 */
class ImportTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        $this->actingAsScheduler();
    }

    public function test_crew_preview_then_commit_creates_and_updates_by_name(): void
    {
        $this->crew('CPT', 'Alpha Captain', ['weekly_hours' => 40]);
        // Semicolons, alias headings, an Excel serial date (46022 = 31 Dec 2025) and a day-first date.
        $csv = "Full name;Position;Home base;Ratings;Hours per week;Medical expiry;Licence\n"
            ."alpha  captain;Captain;LLW;Q400;36;46022;\n"
            ."Nina Banda;Cabin crew;BLZ;;;31/12/2027;2028-01-31\n";
        $preview = $this->preview('crew', 'merge', $csv)->assertOk()->assertJsonPath('data.summary', ['update' => 1, 'create' => 1])->assertJsonPath('data.delimiter', ';');
        $this->assertDatabaseCount('crew_members', 1);

        $this->postJson('/api/v1/imports/commit', ['token' => $preview->json('data.token')])->assertOk()->assertJsonPath('message', 'Import complete: 2 crew members records changed.');
        $alpha = CrewMember::firstWhere('name', 'Alpha Captain');
        $this->assertSame(36, $alpha->weekly_hours);
        $this->assertSame('2025-12-31', $alpha->documents()->firstWhere('kind', 'medical')->expires_on->format('Y-m-d'));
        $this->assertSame('2027-12-31', $alpha->documents()->firstWhere('kind', 'licence')->expires_on->format('Y-m-d'), 'A blank cell keeps the document on record.');
        $nina = CrewMember::firstWhere('name', 'Nina Banda');
        $this->assertTrue($nina->all_aircraft);
        $this->assertSame('2027-12-31', $nina->documents()->firstWhere('kind', 'medical')->expires_on->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', ['entity' => 'imports', 'action' => 'imported']);
    }

    public function test_rows_with_errors_block_the_commit_and_explain_why(): void
    {
        $this->crew('CPT', 'Same Name');
        $this->crew('FO', 'Same Name');
        $csv = "Name,Rank,Base,Aircraft,Medical\nSame Name,CPT,LLW,Q400,\nNew Pilot,Pilot,LLW,Q400,\nOther Pilot,FO,XXX,A320,not-a-date\nCabin One,CC,LLW,ALL,\n";
        $rows = collect($this->preview('crew', 'merge', $csv)->assertOk()->assertJsonPath('data.summary.error', 3)->json('data.rows'))->keyBy('line');

        $this->assertSame('2 crew members are called Same Name; rename one before importing.', $rows[2]['messages'][0]);
        $this->assertSame('Rank "Pilot" is not CPT, FO or CC.', $rows[3]['messages'][0]);
        $this->assertSame(['Base "XXX" is not a crew base.', 'Unknown aircraft type(s): A320.', 'Medical date "not-a-date" is not a date (use YYYY-MM-DD or DD/MM/YYYY).'], $rows[4]['messages']);
        $this->assertSame('create', $rows[5]['action']);
        $this->postJson('/api/v1/imports/commit', ['token' => $this->preview('crew', 'merge', $csv)->json('data.token')])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertDatabaseMissing('crew_members', ['name' => 'Cabin One']);
    }

    public function test_stale_preview_and_missing_columns_are_refused(): void
    {
        $this->preview('crew', 'merge', "Name,Base\nX,LLW\n")->assertUnprocessable()->assertJsonPath('errors.file.0', 'Missing column(s): rank. Download the template to see the expected headings.');
        $token = $this->preview('crew', 'merge', "Name,Rank,Base,Aircraft\nNew Pilot,CPT,LLW,Q400\n")->json('data.token');
        $this->crew('CC', 'Edited Meanwhile');
        $this->postJson('/api/v1/imports/commit', ['token' => $token])->assertUnprocessable()->assertJsonPath('errors.token.0', 'The data changed since this preview (someone else may have edited it). Preview the file again.');
        $this->assertDatabaseMissing('crew_members', ['name' => 'New Pilot']);
    }

    public function test_replace_mode_deactivates_crew_missing_from_the_file(): void
    {
        $this->crew('CPT', 'Kept Captain');
        $gone = $this->crew('CPT', 'Gone Captain');
        $preview = $this->preview('crew', 'replace', "Name,Rank,Base\nKept Captain,CPT,LLW\n")->assertJsonPath('data.summary', ['unchanged' => 1, 'deactivate' => 1]);
        $this->postJson('/api/v1/imports/commit', ['token' => $preview->json('data.token')])->assertOk();
        $this->assertFalse($gone->fresh()->active);
    }

    public function test_flights_are_parsed_and_validated_like_the_editor(): void
    {
        $this->flight('LB1', [0]);
        $csv = "Code,Aircraft,Days,Legs,Active\n"
            ."LB1,Q400,Mon Wed Fri,LLW-BLZ 08:00-09:00; BLZ-LLW 09:40-10:40,yes\n"
            ."LB9,Q400,1.1.1..,LLW-BLZ 06:00-07:00; BLZ-LLW 07:40-08:40,no\n"
            ."LX1,Q400,2,LLW-BLZ 08:00-09:00,yes\n";
        $preview = $this->preview('flights', 'merge', $csv)->assertOk();
        $rows = collect($preview->json('data.rows'))->keyBy('label');
        $this->assertSame('update', $rows['LB1']['action']);
        $this->assertSame('create', $rows['LB9']['action']);
        $this->assertSame(['The trip must finish back at its starting base.'], $rows['LX1']['messages']);

        $fixed = str_replace('LX1,Q400,2,LLW-BLZ 08:00-09:00,yes', 'LX1,Q400,2,LLW-BLZ 06:00-07:00; BLZ-LLW 07:30-08:30,yes', $csv);
        $this->postJson('/api/v1/imports/commit', ['token' => $this->preview('flights', 'merge', $fixed)->json('data.token')])->assertOk();
        $this->assertSame([0, 2, 4], Flight::firstWhere('code', 'LB1')->days()->pluck('weekday')->all());
        $this->assertFalse(Flight::firstWhere('code', 'LB9')->active);
        $this->assertSame([1], Flight::firstWhere('code', 'LX1')->days()->pluck('weekday')->all());
    }

    public function test_day_planning_imports_ranges_and_times_and_replace_overwrites(): void
    {
        $crew = $this->crew('CPT', 'Alpha Captain');
        $crew->activities()->create(['date' => '2026-10-13', 'type' => 'day_off']);
        $csv = "Crew,Type,From,To,Starts,Ends,Note\nAlpha Captain,AL,12/10/2026,14/10/2026,,,Annual leave\nAlpha Captain,SIM,2026-10-20,,06:00,10:00,\n";

        $this->preview('activities', 'merge', $csv)->assertJsonPath('data.summary.error', 1);
        $preview = $this->preview('activities', 'replace', $csv)->assertJsonPath('data.summary', ['create' => 2, 'delete' => 1]);
        $this->postJson('/api/v1/imports/commit', ['token' => $preview->json('data.token')])->assertOk();

        $this->assertSame(['leave', 'leave', 'leave', 'sim'], CrewActivity::query()->orderBy('date')->pluck('type')->all());
        $this->assertSame('2026-10-20 04:00:00', CrewActivity::firstWhere('type', 'sim')->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_templates_download_and_crew_cannot_import(): void
    {
        $template = $this->get('/api/v1/imports/templates/flights')->assertOk()->getContent();
        $this->assertStringContainsString('LLW-BLZ 08:00-09:00; BLZ-LLW 09:40-10:40', $template);

        Sanctum::actingAs(User::factory()->create(['role' => 'crew', 'crew_member_id' => $this->crew('CC', 'Crew One')->id]), ['*']);
        $this->preview('crew', 'merge', "Name,Rank,Base\nX,CC,LLW\n")->assertForbidden();
    }

    /** Upload CSV text for preview. */
    private function preview(string $kind, string $mode, string $csv): TestResponse
    {
        return $this->post('/api/v1/imports/preview', ['kind' => $kind, 'mode' => $mode, 'file' => UploadedFile::fake()->createWithContent($kind.'.csv', $csv)], ['Accept' => 'application/json']);
    }
}
