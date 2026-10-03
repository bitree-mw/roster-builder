<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CrewActivity;
use App\Models\CrewMember;
use App\Models\RuleSet;
use App\Support\Roster\Duty;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Accumulated hours for pilots and cabin crew, built from published rosters only (what crew were actually
 * given). Each duty period counts in the window of its base-local report date and is "flown" once its
 * release time has passed, otherwise "scheduled".
 *
 * Block = flying time on rostered trips. Duty = trip duty (report to release) plus timed SIM and standby
 * sessions, which count as duty just as they do for the duty rules. Draft weeks are never counted.
 */
class CrewHoursService
{
    /** Reporting windows: calendar week, month and year, and rolling 28 days and 12 months ending today. */
    public const WINDOWS = ['week' => 'This week', 'month' => 'This month', 'days_28' => 'Last 28 days', 'year' => 'This year', 'months_12' => 'Last 12 months'];

    public function __construct(private ExpiryService $expiry) {}

    /**
     * Hours for each crew member, keyed by crew member id.
     *
     * @param  Collection<int, CrewMember>  $crews
     * @return array<int, array<string, array{block_minutes: int, duty_minutes: int, scheduled_block_minutes: int, scheduled_duty_minutes: int, trips: int}>>
     */
    public function summaries(Collection $crews): array
    {
        $ranges = $this->ranges();
        $from = collect($ranges)->min(fn (array $range): string => $range[0]);
        $to = collect($ranges)->max(fn (array $range): string => $range[1]);
        $now = intdiv(CarbonImmutable::now('UTC')->getTimestamp(), 60);
        $ids = $crews->pluck('id')->all();
        $totals = [];
        foreach ($ids as $id) {
            $totals[$id] = array_map(fn (): array => ['block_minutes' => 0, 'duty_minutes' => 0, 'scheduled_block_minutes' => 0, 'scheduled_duty_minutes' => 0, 'trips' => 0], $ranges);
        }

        // Rostered trips in published weeks (a rotation can start up to max_trip_days - 1 days before a window and reach into it).
        $assignments = Assignment::query()->whereIn('crew_member_id', $ids)
            ->whereHas('trip', fn ($query) => $query->whereBetween('start_date', [CarbonImmutable::parse($from)->subDays((int) config('roster.max_trip_days') - 1)->format('Y-m-d'), $to])
                ->whereHas('rosterPeriod', fn ($query) => $query->where('status', 'published')))
            ->with('trip')->get();
        foreach ($assignments as $assignment) {
            $duty = Duty::fromSnapshot('assignment:'.$assignment->id, $assignment->trip_id, $assignment->trip->schedule_snapshot);
            if ($duty === null) {
                continue;
            }
            $counted = [];
            foreach ($duty->periods as $period) {
                foreach ($ranges as $window => [$start, $end]) {
                    if ($period['date'] < $start || $period['date'] > $end) {
                        continue;
                    }
                    $flown = $period['end'] <= $now;
                    $totals[$assignment->crew_member_id][$window][$flown ? 'block_minutes' : 'scheduled_block_minutes'] += $period['block'];
                    $totals[$assignment->crew_member_id][$window][$flown ? 'duty_minutes' : 'scheduled_duty_minutes'] += $period['end'] - $period['start'];
                    // A trip counts once per window even when it has several duty periods (night stops).
                    if (! isset($counted[$window])) {
                        $totals[$assignment->crew_member_id][$window]['trips']++;
                        $counted[$window] = true;
                    }
                }
            }
        }

        // Timed SIM and standby sessions count as duty; generated standby only once its week is published.
        $activities = CrewActivity::query()->whereIn('crew_member_id', $ids)->whereIn('type', ['sim', 'standby'])
            ->whereNotNull('starts_at')->whereNotNull('ends_at')->whereBetween('date', [$from, $to])
            ->where(fn ($query) => $query->whereNull('roster_period_id')->orWhereHas('rosterPeriod', fn ($query) => $query->where('status', 'published')))
            ->get();
        foreach ($activities as $activity) {
            $date = $activity->date->format('Y-m-d');
            $minutes = max(0, intdiv($activity->ends_at->getTimestamp() - $activity->starts_at->getTimestamp(), 60));
            $flown = intdiv($activity->ends_at->getTimestamp(), 60) <= $now;
            foreach ($ranges as $window => [$start, $end]) {
                if ($date >= $start && $date <= $end) {
                    $totals[$activity->crew_member_id][$window][$flown ? 'duty_minutes' : 'scheduled_duty_minutes'] += $minutes;
                }
            }
        }

        return $totals;
    }

    /**
     * Window boundaries as inclusive base-local dates.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function ranges(): array
    {
        $today = $this->expiry->today();
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);

        return [
            'week' => [$monday->format('Y-m-d'), $monday->addDays(6)->format('Y-m-d')],
            'month' => [$today->startOfMonth()->format('Y-m-d'), $today->endOfMonth()->format('Y-m-d')],
            'days_28' => [$today->subDays(27)->format('Y-m-d'), $today->format('Y-m-d')],
            'year' => [$today->startOfYear()->format('Y-m-d'), $today->endOfYear()->format('Y-m-d')],
            'months_12' => [$today->subDays(364)->format('Y-m-d'), $today->format('Y-m-d')],
        ];
    }

    /**
     * Report metadata: today, window labels and boundaries, and the monthly block limit to compare against.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'today' => $this->expiry->today()->format('Y-m-d'),
            'windows' => collect($this->ranges())->map(fn (array $range, string $key): array => ['key' => $key, 'label' => self::WINDOWS[$key], 'from' => $range[0], 'to' => $range[1]])->values()->all(),
            'max_block_month_h' => (float) (RuleSet::query()->whereKey(1)->value('max_block_month_h') ?? 100),
        ];
    }
}
