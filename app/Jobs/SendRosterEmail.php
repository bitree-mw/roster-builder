<?php

namespace App\Jobs;

use App\Mail\RosterMail;
use App\Models\EmailLog;
use App\Services\RosterEmailService;
use App\Services\RosterExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one crew member's roster email from the queue and records the outcome on its email log row
 * (sent with the time, or failed with the reason). Runs on the configured queue connection, so a queue
 * worker must be running (php artisan queue:work, or composer run dev locally).
 */
class SendRosterEmail implements ShouldQueue
{
    use Queueable;

    /** One attempt: a failure is recorded on the log and can be re-sent by queuing the week again. */
    public int $tries = 1;

    public function __construct(public int $emailLogId) {}

    /**
     * Build the crew member's roster (days, flights with their crewmates, planned days) and calendar from the
     * published roster, send, and log the result.
     */
    public function handle(RosterExportService $exports, RosterEmailService $emails): void
    {
        $log = EmailLog::query()->with(['crewMember', 'rosterPeriod'])->find($this->emailLogId);
        if ($log === null || $log->status !== 'queued') {
            return;
        }
        try {
            $period = $log->rosterPeriod;
            $crew = $log->crewMember;
            Mail::to($log->email, $crew->name)->send(new RosterMail($period, $crew, $emails->roster($period, $crew), $exports->calendar($period, $crew)));
            $log->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
        } catch (Throwable $exception) {
            $log->forceFill(['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 480)])->save();
        }
    }
}
