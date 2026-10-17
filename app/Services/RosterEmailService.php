<?php

namespace App\Services;

use App\Jobs\SendRosterEmail;
use App\Models\CrewMember;
use App\Models\EmailLog;
use App\Models\RosterPeriod;
use App\Models\User;
use App\Support\Roster\Section;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Roster emails for a published roster: one email (with a calendar attachment) per crew member who holds a
 * seat in it, each tracked by an email log row (queued → sent or failed). They are sent straight away by
 * default (send(), for hosts such as cPanel without a queue worker) or queued with ROSTER_MAIL_DELIVERY=queue
 * (queue()). Crew without an email address are skipped and counted. Nothing is ever sent for drafts or by
 * the demo seeder.
 *
 * Each email lists what changed for its crew member since they were last told (RosterPeriodService::
 * pendingChanges()); once it is sent or queued, that crew member counts as up to date (markNotified()).
 */
class RosterEmailService
{
    /** How planned days read in the email. */
    private const ACTIVITIES = ['leave' => 'Leave', 'day_off' => 'Day off', 'sim' => 'Simulator session', 'standby' => 'Standby'];

    public function __construct(private AuditService $audit, private RosterPeriodService $periods) {}

    /**
     * Create the log rows and dispatch one job per recipient once the transaction commits.
     * $crewIds limits the recipients (e.g. crew whose duties changed); null means everyone with a seat.
     *
     * @param  array<int, int>|null  $crewIds
     * @return array{queued: int, missing: int}
     *
     * @throws ValidationException when the week is not published
     */
    public function queue(RosterPeriod $period, User $actor, ?array $crewIds = null): array
    {
        if ($period->status !== 'published') {
            throw ValidationException::withMessages(['period' => 'Publish this roster before emailing it to crew.']);
        }

        return DB::transaction(function () use ($period, $actor, $crewIds): array {
            $this->recordChanges($period);
            $crews = $this->recipients($period, $crewIds);
            $queued = 0;
            $missing = 0;
            $notified = [];
            foreach ($crews as $crew) {
                if (blank($crew->email)) {
                    $missing++;

                    continue;
                }
                $log = EmailLog::query()->create(['crew_member_id' => $crew->id, 'roster_period_id' => $period->id, 'email' => $crew->email, 'requested_by' => $actor->id, 'status' => 'queued']);
                SendRosterEmail::dispatch($log->id)->afterCommit();
                $queued++;
                $notified[] = $crew->id;
            }
            // Queued crew are counted as told; a failed send can be retried with "Email crew".
            $this->periods->markNotified($period, $notified);
            $this->audit->record($actor, 'emailed', $period, null, ['queued' => $queued, 'missing' => $missing]);

            return ['queued' => $queued, 'missing' => $missing];
        });
    }

    /**
     * Send the roster emails straight away, in this request, for hosts without a queue worker (e.g. cPanel).
     * Each recipient still gets an email log row (sent, or failed with the reason). A row left "queued" by an
     * earlier queued attempt is reused, so nobody receives the same roster twice when it is finally sent.
     * $crewIds limits the recipients as for queue().
     *
     * @param  array<int, int>|null  $crewIds
     * @return array{sent: int, failed: int, missing: int, error: string|null} error: the first failure reason
     *
     * @throws ValidationException when the roster is not published
     */
    public function send(RosterPeriod $period, User $actor, ?array $crewIds = null): array
    {
        if ($period->status !== 'published') {
            throw ValidationException::withMessages(['period' => 'Publish this roster before emailing it to crew.']);
        }
        [$logs, $missing] = DB::transaction(function () use ($period, $actor, $crewIds): array {
            $this->recordChanges($period);
            $crews = $this->recipients($period, $crewIds);
            $logs = [];
            $missing = 0;
            foreach ($crews as $crew) {
                if (blank($crew->email)) {
                    $missing++;

                    continue;
                }
                $log = EmailLog::query()->where('roster_period_id', $period->id)->where('crew_member_id', $crew->id)->where('status', 'queued')->first()
                    ?? new EmailLog(['crew_member_id' => $crew->id, 'roster_period_id' => $period->id, 'status' => 'queued']);
                $log->fill(['email' => $crew->email, 'requested_by' => $actor->id])->save();
                $logs[] = $log;
            }
            $this->audit->record($actor, 'emailed', $period, null, ['direct' => count($logs), 'missing' => $missing]);

            return [$logs, $missing];
        });
        // Sent one by one after the log rows are committed; SendRosterEmail records sent or failed on each row.
        foreach ($logs as $log) {
            SendRosterEmail::dispatchSync($log->id);
        }
        $results = EmailLog::query()->whereKey(array_map(fn (EmailLog $log): int => $log->id, $logs))->get();
        // Crew who got their roster are up to date; those whose email failed stay listed as changed.
        $this->periods->markNotified($period, $results->where('status', 'sent')->pluck('crew_member_id')->map(fn ($id): int => (int) $id)->all());

        return [
            'sent' => $results->where('status', 'sent')->count(),
            'failed' => $results->where('status', 'failed')->count(),
            'missing' => $missing,
            'error' => $results->firstWhere('status', 'failed')?->error,
        ];
    }

    /**
     * One crew member's roster as the email shows it: every day of the period with their flights (times in
     * base local and GMT, legs, and who they fly with: captain, first officer and each cabin crew seat), night
     * stops between the days of a multi-day trip, and planned leave, days off, SIM and standby; plus totals.
     *
     * @return array{label: string, range: string, totals: array{flights: int, duties: int, block: string, duty: string, planned: int}, days: array<int, array{date: string, weekday: string, day: string, entries: array<int, array<string, mixed>>}>, changes: array{crew_member_id: int, name: string, removed: array<int, string>, added: array<int, string>}|null}
     */
    public function roster(RosterPeriod $period, CrewMember $crew): array
    {
        $offset = (int) ($period->rules_snapshot['utc_offset_minutes'] ?? 120);
        $entries = [];
        $totals = ['flights' => 0, 'duties' => 0, 'block' => 0, 'duty' => 0, 'planned' => 0];
        $trips = $period->trips()->whereHas('assignments', fn ($query) => $query->where('crew_member_id', $crew->id))
            ->with('assignments.crewMember')->orderBy('start_date')->get();
        foreach ($trips as $trip) {
            $own = $trip->assignments->firstWhere('crew_member_id', $crew->id);
            // A captain who flies only a section gets just that section's duties.
            $snapshot = Section::restrict($trip->schedule_snapshot, $own->from_leg, $own->to_leg);
            // Everyone on the trip, captain first, then first officer and cabin crew by seat; open seats say so.
            // Captains who share the trip are labelled with their section, e.g. "Captain (LLW-NBO-LLW)".
            $team = $trip->assignments->sortBy(fn ($seat): array => [array_search($seat->rank, ['CPT', 'FO', 'CC'], true), $seat->seat_number])
                ->map(fn ($seat): array => ['role' => $this->seatLabel($seat->rank, $seat->seat_number).(Section::isPartial($trip->schedule_snapshot, $seat->from_leg, $seat->to_leg) ? ' ('.Section::route($trip->schedule_snapshot, $seat->from_leg, $seat->to_leg).')' : ''),
                    'name' => $seat->crewMember?->name ?? 'Not yet assigned', 'you' => $seat->id === $own->id, 'open' => $seat->crew_member_id === null])->values()->all();
            $duties = $snapshot['duties'] ?? [];
            $totals['flights']++;
            foreach ($duties as $index => $duty) {
                $legs = array_map(fn (array $leg): array => ['from' => $leg['from_airport'], 'to' => $leg['to_airport'], 'departs_local' => $leg['departs_local'], 'arrives_local' => $leg['arrives_local']], $duty['legs'] ?? []);
                $entries[$duty['date']][] = [
                    'kind' => 'duty', 'code' => $snapshot['code'] ?? '', 'route' => $snapshot['route'] ?? '', 'aircraft' => $snapshot['aircraft_label'] ?? $snapshot['aircraft_type'] ?? '',
                    'seat' => $this->seatLabel($own->rank, $own->seat_number),
                    'report_local' => $duty['report_local'], 'release_local' => $duty['release_local'],
                    'report_gmt' => CarbonImmutable::parse($duty['report'])->utc()->format('H:i'), 'release_gmt' => CarbonImmutable::parse($duty['release'])->utc()->format('H:i'),
                    'day_of_trip' => count($duties) > 1 ? 'Day '.($index + 1).' of '.count($duties) : null,
                    'block' => $this->hours((int) $duty['block_minutes']), 'legs' => $legs, 'crew' => $team,
                ];
                $totals['duties']++;
                $totals['block'] += (int) $duty['block_minutes'];
                $totals['duty'] += (int) $duty['duty_minutes'];
                // Days between this duty and the next one of the trip are spent at the outstation (night stop).
                $next = $duties[$index + 1] ?? null;
                if ($next !== null) {
                    $airport = end($legs)['to'] ?? '';
                    for ($day = CarbonImmutable::parse(max($duty['dates']), 'UTC')->addDay(); $day->format('Y-m-d') < min($next['dates']); $day = $day->addDay()) {
                        $entries[$day->format('Y-m-d')][] = ['kind' => 'layover', 'code' => $snapshot['code'] ?? '', 'airport' => $airport];
                    }
                }
            }
        }
        $from = $period->starts_on->format('Y-m-d');
        $to = $period->ends_on->format('Y-m-d');
        foreach ($crew->activities()->whereBetween('date', [$from, $to])->orderBy('date')->get() as $activity) {
            $times = $activity->starts_at && $activity->ends_at
                ? $activity->starts_at->utc()->addMinutes($offset)->format('H:i').'–'.$activity->ends_at->utc()->addMinutes($offset)->format('H:i')
                : null;
            $entries[$activity->date->format('Y-m-d')][] = ['kind' => 'activity', 'type' => $activity->type, 'label' => self::ACTIVITIES[$activity->type] ?? ucfirst($activity->type), 'times' => $times, 'note' => $activity->note];
            $totals['planned']++;
        }
        // Every day of the roster (and any day a trip runs past its end), in order, empty days included.
        $dates = array_unique([...$period->dates(), ...array_keys($entries)]);
        sort($dates);

        return [
            'label' => ucfirst($period->label()),
            'range' => $period->starts_on->format('D d M').' – '.$period->ends_on->format('D d M Y'),
            'totals' => [...$totals, 'block' => $this->hours($totals['block']), 'duty' => $this->hours($totals['duty'])],
            'days' => array_map(fn (string $date): array => [
                'date' => $date, 'weekday' => CarbonImmutable::parse($date)->format('D'), 'day' => CarbonImmutable::parse($date)->format('d M'), 'entries' => $entries[$date] ?? [],
            ], $dates),
            // What changed for this crew member when the roster was last published again (null: nothing).
            'changes' => collect($period->changed_crew ?? [])->firstWhere('crew_member_id', $crew->id),
        ];
    }

    /**
     * Store the crew's changes since they were last told on the roster (changed_crew), so each email of this
     * send can list "Changes since your last roster" for its crew member.
     */
    private function recordChanges(RosterPeriod $period): void
    {
        $period->changed_crew = $this->periods->pendingChanges($period);
        $period->save();
    }

    /**
     * Who receives the emails: the given crew (e.g. those whose duties changed, even if they no longer hold a
     * seat) or everyone with a seat in the roster.
     *
     * @param  array<int, int>|null  $crewIds
     * @return Collection<int, CrewMember>
     */
    private function recipients(RosterPeriod $period, ?array $crewIds): Collection
    {
        if ($crewIds !== null) {
            return CrewMember::query()->whereKey($crewIds)->orderBy('name')->get();
        }

        return CrewMember::query()->whereHas('assignments.trip', fn ($query) => $query->where('roster_period_id', $period->id))->orderBy('name')->get();
    }

    /** "Captain", "First officer" or "Cabin crew 2". */
    private function seatLabel(string $rank, ?int $seatNumber): string
    {
        return match ($rank) {
            'CPT' => 'Captain',
            'FO' => 'First officer',
            default => 'Cabin crew'.($seatNumber ? ' '.$seatNumber : ''),
        };
    }

    /** 605 -> "10h05". */
    private function hours(int $minutes): string
    {
        return intdiv($minutes, 60).'h'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
