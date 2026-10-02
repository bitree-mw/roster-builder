<?php

namespace App\Services;

use App\Jobs\SendRosterEmail;
use App\Models\CrewMember;
use App\Models\EmailLog;
use App\Models\RosterPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queues roster emails for a published week: one email (with a calendar attachment) per crew member who
 * holds a seat in it, each tracked by an email log row (queued → sent or failed). Crew without an email
 * address are skipped and counted. Nothing is ever sent for drafts or by the demo seeder.
 */
class RosterEmailService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Create the log rows and dispatch one job per recipient once the transaction commits.
     *
     * @return array{queued: int, missing: int}
     *
     * @throws ValidationException when the week is not published
     */
    public function queue(RosterPeriod $period, User $actor): array
    {
        if ($period->status !== 'published') {
            throw ValidationException::withMessages(['period' => 'Publish this roster before emailing it to crew.']);
        }

        return DB::transaction(function () use ($period, $actor): array {
            $crews = CrewMember::query()->whereHas('assignments.trip', fn ($query) => $query->where('roster_period_id', $period->id))->orderBy('name')->get();
            $queued = 0;
            $missing = 0;
            foreach ($crews as $crew) {
                if (blank($crew->email)) {
                    $missing++;

                    continue;
                }
                $log = EmailLog::query()->create(['crew_member_id' => $crew->id, 'roster_period_id' => $period->id, 'email' => $crew->email, 'requested_by' => $actor->id, 'status' => 'queued']);
                SendRosterEmail::dispatch($log->id)->afterCommit();
                $queued++;
            }
            $this->audit->record($actor, 'emailed', $period, null, ['queued' => $queued, 'missing' => $missing]);

            return ['queued' => $queued, 'missing' => $missing];
        });
    }
}
