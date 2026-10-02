<?php

namespace App\Mail;

use App\Models\CrewMember;
use App\Models\RosterPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A crew member's published roster for one week: a summary of their duties in the message body and an
 * .ics calendar attachment, signed by crew control (config('roster.mail_signature')).
 */
class RosterMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{date: string, code: string, route: string, seat: string, report_local: string, release_local: string}>  $duties  base-local duty lines for the body
     * @param  string  $calendar  iCalendar file contents
     */
    public function __construct(public RosterPeriod $period, public CrewMember $crew, public array $duties, public string $calendar) {}

    /**
     * Subject line, e.g. "Your roster: week 41 (05–11 Oct 2026)".
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your roster: '.$this->period->label());
    }

    /**
     * HTML and plain-text bodies.
     */
    public function content(): Content
    {
        return new Content(view: 'mail.roster', text: 'mail.roster-text', with: ['signature' => config('roster.mail_signature')]);
    }

    /**
     * The week as a calendar file for the crew member's phone or mail app.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->calendar, 'roster-week-'.$this->period->starts_on->isoWeek.'.ics')->withMime('text/calendar')];
    }
}
