<?php

namespace App\Mail;

use App\Models\CrewMember;
use App\Models\RosterPeriod;
use App\Services\PdfService;
use App\Support\ThemeTokens;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * A crew member's published roster (1 week, 2 weeks or a month) in the app's colours, kept simple: the logo,
 * a one-line summary and the days with something on (flights with local and GMT times and who they fly with,
 * night stops and planned days). Attached: the same roster as a PDF with full detail, and an .ics calendar.
 *
 * Mail clients ignore CSS variables and most <style> blocks, so resources/css/mail/roster.css is resolved
 * against the app's colour tokens (ThemeTokens) and inlined into the HTML. The logo is embedded (cid:) because
 * Gmail blocks data: images; ROSTER_MAIL_LOGO=false leaves it out.
 */
class RosterMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Content id of the embedded logo, referenced as cid:… in the HTML. */
    private const LOGO_CID = 'malawi-airlines-logo';

    /**
     * @param  array<string, mixed>  $roster  RosterEmailService::roster() for this crew member and period
     * @param  string  $calendar  iCalendar file contents
     */
    public function __construct(public RosterPeriod $period, public CrewMember $crew, public array $roster, public string $calendar) {}

    /**
     * Subject line, e.g. "Your roster: Weeks 41–42 (05–18 Oct 2026)", with the logo embedded when enabled.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your roster: '.$this->roster['label'], using: [function (Email $message): void {
            if ($this->logoPath() !== null) {
                $message->embedFromPath($this->logoPath(), self::LOGO_CID, 'image/png');
            }
        }]);
    }

    /**
     * The HTML body with the stylesheet inlined, and the plain-text version.
     */
    public function content(): Content
    {
        $styles = ThemeTokens::resolve((string) file_get_contents(resource_path('css/mail/roster.css')));
        $data = ['roster' => $this->roster, 'crew' => $this->crew, 'signature' => config('roster.mail_signature')];
        $html = view('mail.roster', [...$data, 'styles' => $styles, 'logo' => $this->logoPath() !== null ? 'cid:'.self::LOGO_CID : null])->render();

        return new Content(htmlString: (new CssToInlineStyles)->convert($html, $styles), text: 'mail.roster-text', with: $data);
    }

    /**
     * The roster as a PDF (the same days and crew as the email, with legs and aircraft) and as a calendar file
     * for the crew member's phone or mail app. The PDF is only rendered when the email is sent.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $name = 'roster-'.$this->period->starts_on->format('Y-m-d');

        return [
            Attachment::fromData(fn (): string => app(PdfService::class)->render('pdf.roster-personal', [
                'title' => 'Crew roster · '.$this->crew->name, 'subtitle' => $this->roster['label'].' · '.$this->roster['range'], 'roster' => $this->roster,
            ], 'portrait'), $name.'.pdf')->withMime('application/pdf'),
            Attachment::fromData(fn (): string => $this->calendar, $name.'.ics')->withMime('text/calendar'),
        ];
    }

    /** The logo file when logos are enabled for email and the file exists. */
    private function logoPath(): ?string
    {
        $path = public_path('images/malawi-airlines-logo.png');

        return config('roster.mail_logo') && is_file($path) ? $path : null;
    }
}
