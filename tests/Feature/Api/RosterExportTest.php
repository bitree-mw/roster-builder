<?php

namespace Tests\Feature\Api;

use App\Jobs\SendRosterEmail;
use App\Mail\RosterMail;
use App\Models\CrewMember;
use App\Models\EmailLog;
use App\Models\RosterPeriod;
use App\Models\User;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Week outputs: CSV (formula-safe, own rows for crew), iCalendar (UTC events, escaped text) and roster
 * emails (published rosters only, sent directly by default or queued, logged per crew member, crew without an
 * address skipped, a stuck queued email sent once, and the mail server's reason shown when every send fails).
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

    public function test_roster_emails_are_sent_directly_and_logged_per_crew_member(): void
    {
        Mail::fake();
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/email")->assertUnprocessable()->assertJsonPath('errors.period.0', 'Publish this roster before emailing it to crew.');
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/publish")->assertOk();

        // No queue worker needed: both crew members with an address get their email in this request.
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/email")->assertOk()
            ->assertJsonPath('message', 'Roster emails sent to 2 crew members. 1 have no email address on file.')->assertJsonPath('data.sent', 2)->assertJsonPath('data.missing', 1);
        $this->assertSame(2, EmailLog::where('status', 'sent')->count());
        Mail::assertSent(RosterMail::class, 2);
        // The captain's email lists the Monday flight with everyone on it, and the calendar file.
        $captain = CrewMember::firstWhere('rank', 'CPT');
        Mail::assertSent(RosterMail::class, function (RosterMail $mail) use ($captain): bool {
            if (! $mail->hasTo($captain->email)) {
                return false;
            }
            $monday = collect($mail->roster['days'])->firstWhere('date', '2026-10-05');
            $mail->assertSeeInOrderInHtml(['Your roster', 'LB1', 'Captain', 'With:', 'Charlie Officer (First officer)', 'Delta Cabin (Cabin crew 1)'])
                ->assertSeeInText('With: Charlie Officer (First officer), Delta Cabin (Cabin crew 1)')->assertDontSeeInHtml('var(--');
            // The same roster attached as a PDF (which must render) and as a calendar file.
            $pdf = app(PdfService::class)->render('pdf.roster-personal', ['title' => 'Crew roster', 'roster' => $mail->roster], 'portrait');

            return array_map(fn ($attachment) => $attachment->as, $mail->attachments()) === ['roster-2026-10-05.pdf', 'roster-2026-10-05.ics']
                && str_starts_with($pdf, '%PDF') && $monday['entries'][0]['code'] === 'LB1' && $mail->roster['totals']['flights'] === 1;
        });
        $this->getJson("/api/v1/roster-periods/{$this->period->id}/email-logs")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_an_email_left_queued_earlier_is_sent_once_not_duplicated(): void
    {
        Mail::fake();
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/publish")->assertOk();
        $captain = CrewMember::firstWhere('rank', 'CPT');
        $stuck = EmailLog::query()->create(['crew_member_id' => $captain->id, 'roster_period_id' => $this->period->id, 'email' => $captain->email, 'status' => 'queued']);

        $this->postJson("/api/v1/roster-periods/{$this->period->id}/email")->assertOk()->assertJsonPath('data.sent', 2);
        $this->assertSame('sent', $stuck->fresh()->status);
        $this->assertSame(1, EmailLog::where('crew_member_id', $captain->id)->count());
    }

    public function test_when_the_mail_server_refuses_every_email_the_reason_is_shown(): void
    {
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/publish")->assertOk();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('535 Incorrect authentication data'));

        $this->postJson("/api/v1/roster-periods/{$this->period->id}/email")->assertUnprocessable()->assertJsonPath('code', 'email_failed')
            ->assertJsonPath('message', 'No roster email could be sent. The mail server said: 535 Incorrect authentication data');
        $this->assertSame(2, EmailLog::where('status', 'failed')->where('error', '535 Incorrect authentication data')->count());
    }

    public function test_with_queued_delivery_emails_wait_for_a_worker(): void
    {
        config(['roster.mail_delivery' => 'queue']);
        Queue::fake();
        $this->postJson("/api/v1/roster-periods/{$this->period->id}/publish")->assertOk();

        $this->postJson("/api/v1/roster-periods/{$this->period->id}/email")->assertOk()
            ->assertJsonPath('message', 'Roster emails queued for 2 crew members. 1 have no email address on file.');
        Queue::assertPushed(SendRosterEmail::class, 2);
        $this->assertSame(2, EmailLog::where('status', 'queued')->count());
    }
}
