<?php

namespace App\Services;

use App\Models\Aircraft;
use App\Models\CrewDocument;
use App\Models\RosterPeriod;
use Carbon\CarbonImmutable;

/**
 * Everything the operations dashboard shows at a glance: the overview counts, the state of the last,
 * current and next two rosters (each 1 week, 2 weeks or a month), live conflicts for rosters that can still
 * change, today's trips, and the fleet, maintenance and crew document items that need attention.
 */
class DashboardService
{
    public function __construct(
        private ExpiryService $expiry,
        private OverviewService $overview,
        private MaintenanceService $maintenance,
        private RosterLegalityService $legality,
        private RosterConflictService $conflicts,
    ) {}

    /**
     * Build the dashboard payload. Rosters that have not been created yet are reported with status "missing"
     * (with suggested dates) so the dashboard can prompt the scheduler to prepare them.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $today = $this->expiry->today();
        $day = $today->format('Y-m-d');
        // Roster periods around today (weeks, fortnights or months): the last one that ended, the one covering
        // today, and the next two. A missing current roster is suggested from this week's Monday, a missing next one
        // from the day after the current roster ends.
        $current = RosterPeriod::query()->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->first();
        $upcoming = RosterPeriod::query()->where('starts_on', '>', $current?->ends_on?->format('Y-m-d') ?? $day)->orderBy('starts_on')->limit(2)->get();
        $slots = [
            'previous' => RosterPeriod::query()->where('ends_on', '<', $day)->orderByDesc('ends_on')->first(),
            'current' => $current,
            'next' => $upcoming->get(0),
            'following' => $upcoming->get(1),
        ];
        $suggested = ['current' => $today->startOfWeek(CarbonImmutable::MONDAY), 'next' => ($current?->ends_on ?? $today->startOfWeek(CarbonImmutable::MONDAY)->addDays(6))->addDay()];

        $weeks = [];
        $conflicts = [];
        $todayTrips = [];
        foreach ($slots as $slot => $period) {
            if ($period === null && ! isset($suggested[$slot])) {
                continue;
            }
            $start = $period?->starts_on ?? $suggested[$slot];
            $end = $period?->ends_on ?? $start->addDays(6);
            $week = ['slot' => $slot, 'id' => $period?->id, 'starts_on' => $start->format('Y-m-d'), 'ends_on' => $end->format('Y-m-d'), 'iso_week' => $start->isoWeek,
                'length' => $period?->length ?? 'week', 'label' => $period ? ucfirst($period->label()) : 'Week '.$start->isoWeek.' ('.$start->format('d M').'–'.$end->format('d M Y').')',
                'status' => $period?->status ?? 'missing', 'built_at' => $period?->built_at?->toIso8601String(), 'published_at' => $period?->published_at?->toIso8601String(), 'summary' => null];
            if ($period !== null) {
                $context = $this->legality->context($period);
                $weekConflicts = $this->conflicts->forPeriod($period, $context);
                $week['summary'] = $this->conflicts->summary($context, $weekConflicts);
                // Live conflicts only matter for weeks that can still change.
                if (in_array($slot, ['current', 'next'], true)) {
                    foreach ($weekConflicts as $conflict) {
                        if ($conflict['date'] >= $today->format('Y-m-d') && $conflict['code'] !== 'open_seat') {
                            $conflicts[] = [...$conflict, 'period_id' => $period->id, 'week_starts_on' => $week['starts_on']];
                        }
                    }
                }
                if ($slot === 'current') {
                    foreach ($context->trips as $trip) {
                        if ($trip->start_date->equalTo($today)) {
                            $snapshot = $trip->schedule_snapshot;
                            $duties = $snapshot['duties'] ?? [];
                            $todayTrips[] = ['trip_id' => $trip->id, 'period_id' => $period->id, 'code' => $snapshot['code'] ?? $trip->flight->code, 'route' => $snapshot['route'] ?? null,
                                'aircraft_type' => $snapshot['aircraft_type'] ?? null, 'palette' => $snapshot['palette'] ?? null,
                                'report' => $snapshot['report'] ?? null, 'release' => $snapshot['release'] ?? null,
                                'report_local' => $duties[0]['report_local'] ?? null, 'release_local' => $duties === [] ? null : end($duties)['release_local'],
                                'seats' => $trip->assignments->count(), 'filled' => $trip->assignments->whereNotNull('crew_member_id')->count()];
                        }
                    }
                }
            }
            $weeks[] = $week;
        }
        // Blocking problems first, then by date.
        usort($conflicts, fn (array $a, array $b): int => [! $a['blocking'], $a['severity'] !== 'danger', $a['date'], $a['flight_code']] <=> [! $b['blocking'], $b['severity'] !== 'danger', $b['date'], $b['flight_code']]);
        usort($todayTrips, fn (array $a, array $b): int => [$a['report'], $a['code']] <=> [$b['report'], $b['code']]);

        $warning = (int) config('roster.document_warning_days');
        $documents = CrewDocument::query()->whereHas('crewMember', fn ($query) => $query->where('active', true))
            ->where('expires_on', '<=', $today->addDays($warning)->format('Y-m-d'))->with('crewMember')->orderBy('expires_on')->limit(8)->get()
            ->map(fn (CrewDocument $document): array => ['crew_member_id' => $document->crew_member_id, 'name' => $document->crewMember->name, 'rank' => $document->crewMember->rank,
                'kind' => $document->kind, 'expires_on' => $document->expires_on->format('Y-m-d'), 'state' => $this->expiry->documentState($document->expires_on), 'days_remaining' => $this->expiry->daysUntil($document->expires_on)])->all();

        return [
            'today' => $today->format('Y-m-d'),
            'overview' => $this->overview->summary(),
            'weeks' => $weeks,
            'conflicts' => array_slice($conflicts, 0, 40),
            'conflict_total' => count($conflicts),
            'today_trips' => $todayTrips,
            'fleet_issues' => Aircraft::query()->where('status', '!=', 'available')->with('aircraftType')->orderByRaw("case status when 'grounded' then 0 when 'maintenance' then 1 else 2 end")->orderBy('registration')->get()
                ->map(fn (Aircraft $aircraft): array => ['id' => $aircraft->id, 'registration' => $aircraft->registration, 'aircraft_type' => $aircraft->aircraftType->code, 'status' => $aircraft->status, 'status_label' => Aircraft::STATUSES[$aircraft->status], 'status_reason' => $aircraft->status_reason])->all(),
            'maintenance_alerts' => $this->maintenance->alerts()->take(6)->values(),
            'document_alerts' => $documents,
        ];
    }
}
