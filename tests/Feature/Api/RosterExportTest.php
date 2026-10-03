<?php

namespace Tests\Feature\Api;

use App\Jobs\SendRosterEmail;
use App\Mail\RosterMail;
use App\Models\CrewMember;
use App\Models\EmailLog;
use App\Models\RosterPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Week outputs: CSV (formula-safe, own rows for crew), iCalendar (UTC events, escaped text) and roster
 * emails (published weeks only, logged per crew member, crew without an address skipped).
 */
class RosterExportTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    private RosterPeriod $period;

    /** One LB1 on Monday with a captain whose name looks like a spreadsheet formula, and a cabin crew member without email. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        $this->flight('LB1', [0]);
        $this->crew('CPT', '=HYPERLINK("x"), Smith');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin', ['email' => null]);
        $this->period = $this->week();
        $this->actingAsScheduler();
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/build")->assertOk();
    }

    public function test_csv_neutralises_formulas_and_crew_only_get_their_own_published_rows(): void
    {
        $csv = $this->get("/api/v1/roster-periods/{$this->period->id}/export.csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"x\"\"), Smith\"", $csv);
        $this->assertStringContainsString('Charlie Officer', $csv);
        // Header plus the CPT, FO and CC seats.
        $this->assertCount(4, explode("\n", trim($csv)));

        $officer = User::factory()->create(['role' => 'crew', 'crew_member_id' => CrewMember::firstWhere('name', 'Charlie Officer')->id]);
        Sanctum::actingAs($officer, ['*']);
        $this->get("/api/v1/roster-periods/{$this->period->id}/export.csv")->assertNotFound();
        $this->period->forceFill(['status' => 'published'])->save();
        $own = $this->get("/api/v1/roster-periods/{$this->period->id}/export.csv")->assertOk()->getContent();
        $this->assertStringContainsString('Charlie Officer', $own);
        $this->assertStringNotContainsString('Smith', $own);
    }

    public function test_calendar_has_utc_events_and_escaped_text(): void
    {
        $this->get("/api/v1/roster-periods/{$this->period->id}/calendar.ics", ['Accept' => 'application/json'])->assertUnprocessable();
        $captain = CrewMember::firstWhere('rank', 'CPT');
        $ics = $this->get("/api/v1/roster-periods/{$this->period->id}/calendar.ics?crew_member_id={$captain->id}")->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')->getContent();

        // LB1 departs 08:00 LT; report 07:00 LT = 05:00 UTC, release 11:10 LT = 09:10 UTC.
        $this->assertStringContainsString("DTSTART:20261005T050000Z\r\nDTEND:20261005T091000Z\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:LB1 CPT LLW-BLZ-LLW', $ics);
        $this->assertStringContainsString('=HYPERLINK("x")\\, Smith', $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    public function test_roster_emails_are_queued_for_published_weeks_and_logged_per_crew_member(): void
    {
        Queue::fake();
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/email")->assertUnprocessable()->assertJsonPath('errors.period.0', 'Publish this roster before emailing it to crew.');
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/publish")->assertOk();

        $this->postJson("/api/v1/roster-periods/{$this->period->id}/email")->assertOk()
            ->assertJsonPath('message', 'Roster emails queued for 2 crew members. 1 have no email address on file.')->assertJsonPath('data.missing', 1);
        Queue::assertPushed(SendRosterEmail::class, 2);
        $this->assertSame(2, EmailLog::where('status', 'queued')->count());

        Mail::fake();
        $log = EmailLog::query()->with('crewMember')->firstOrFail();
        app()->call([new SendRosterEmail($log->id), 'handle']);
        // The captain's email lists the Monday flight with everyone on it, and the calendar file.
        Mail::assertSent(RosterMail::class, function (RosterMail $mail) use ($log): bool {
            $monday = collect($mail->roster['days'])->firstWhere('date', '2026-10-05');
            $mail->assertSeeInOrderInHtml(['Your published roster', 'LB1', 'Captain', '(you)', 'First officer', 'Charlie Officer', 'Cabin crew 1', 'Delta Cabin'])
                ->assertSeeInText('Flying with: Captain')->assertDontSeeInHtml('var(--');

            return $mail->hasTo($log->email) && count($mail->attachments()) === 1 && $monday['entries'][0]['code'] === 'LB1' && $mail->roster['totals']['flights'] === 1;
        });
        $this->assertSame('sent', $log->fresh()->status);
        $this->getJson("/api/v1/roster-periods/{$this->period->id}/email-logs")->assertOk()->assertJsonCount(2, 'data');
    }
}
