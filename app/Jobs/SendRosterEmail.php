<?php

namespace App\Jobs;

use App\Mail\RosterMail;
use App\Models\EmailLog;
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
     * Build the duty summary and calendar from the published week, send, and log the result.
     */
    public function handle(RosterExportService $exports): void
    {
        $log = EmailLog::query()->with(['crewMember', 'rosterPeriod'])->find($this->emailLogId);
        if ($log === null || $log->status !== 'queued') {
            return;
        }
        try {
            $period = $log->rosterPeriod;
            $crew = $log->crewMember;
            $duties = [];
            foreach ($period->trips()->whereHas('assignments', fn ($query) => $query->where('crew_member_id', $crew->id))->with('assignments')->orderBy('start_date')->get() as $trip) {
                $seat = $trip->assignments->firstWhere('crew_member_id', $crew->id);
                foreach ($trip->schedule_snapshot['duties'] ?? [] as $duty) {
                    $duties[] = ['date' => $duty['date'], 'code' => $trip->schedule_snapshot['code'] ?? '', 'route' => $trip->schedule_snapshot['route'] ?? '',
                        'seat' => $seat->rank === 'CC' ? 'CC'.$seat->seat_number : $seat->rank, 'report_local' => $duty['report_local'], 'release_local' => $duty['release_local']];
                }
            }
            Mail::to($log->email, $crew->name)->send(new RosterMail($period, $crew, $duties, $exports->calendar($period, $crew)));
            $log->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
        } catch (Throwable $exception) {
            $log->forceFill(['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 480)])->save();
        }
    }
}
