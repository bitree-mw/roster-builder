<?php

namespace App\Services;

use App\Models\Aircraft;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\CrewDocument;
use App\Models\CrewMember;
use App\Models\RosterPeriod;
use App\Models\RuleSet;
use App\Models\Trip;
use App\Support\Roster\Duty;
use Carbon\CarbonImmutable;

/**
 * Operational reports for a date range. Every report has the same shape so the Reports page, CSV and PDF
 * outputs share one definition:
 *
 *   title, description, columns [{key, label, align}], rows [{key => value, _tone => {key => tone}}],
 *   summary [label => value], notes.
 *
 * Dates are base-local; "flown" means a duty's release time has passed. Hours count published weeks only
 * (as on the Crew hours page); coverage, flights and overrides look at every week, draft or published.
 */
class ReportService
{
    /** Report keys with their titles, in the order shown on the Reports page. */
    public const TYPES = [
        'coverage' => 'Roster coverage by week',
        'hours' => 'Crew hours for a period',
        'overrides' => 'Manual overrides and locked seats',
        'documents' => 'Crew document expiry',
        'maintenance' => 'Fleet status and maintenance due',
        'flights' => 'Flight pattern utilisation',
        'audit' => 'Audit log',
    ];

    private const RANKS = ['CPT' => 'Captain', 'FO' => 'First officer', 'CC' => 'Cabin crew'];

    public function __construct(
        private ExpiryService $expiry,
        private RosterLegalityService $legality,
        private RosterConflictService $conflicts,
        private MaintenanceService $maintenance,
    ) {}

    /**
     * Build one report.
     *
     * @param  array{from: string, to: string, group?: ?string}  $filters
     * @return array{title: string, description: string, columns: array<int, array{key: string, label: string, align?: string}>, rows: array<int, array<string, mixed>>, summary: array<string, string|int>, notes: string}
     */
    public function build(string $type, array $filters): array
    {
        $from = CarbonImmutable::parse($filters['from'], 'UTC');
        $to = CarbonImmutable::parse($filters['to'], 'UTC');

        return [...match ($type) {
            'coverage' => $this->coverage($from, $to),
            'hours' => $this->hours($from, $to, $filters['group'] ?? null),
            'overrides' => $this->overrides($from, $to),
            'documents' => $this->documents($to),
            'maintenance' => $this->maintenanceDue(),
            'flights' => $this->flights($from, $to),
            'audit' => $this->audit($from, $to),
        }, 'title' => self::TYPES[$type], 'period' => $from->format('d M Y').' – '.$to->format('d M Y')];
    }

    /** Seats, fill rate and conflicts for every roster week starting in the range. */
    private function coverage(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $totals = ['seats' => 0, 'filled' => 0, 'blocking' => 0];
        foreach (RosterPeriod::query()->whereBetween('starts_on', [$from->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'), $to->format('Y-m-d')])->orderBy('starts_on')->get() as $period) {
            $context = $this->legality->context($period);
            $summary = $this->conflicts->summary($context, $this->conflicts->forPeriod($period, $context));
            $rate = $summary['seats'] ? round(100 * $summary['filled'] / $summary['seats'], 1) : 0;
            $overrides = $context->trips->sum(fn (Trip $trip): int => $trip->assignments->filter(fn (Assignment $seat): bool => ! empty($seat->flag_reasons))->count());
            $rows[] = ['week' => 'Week '.$period->starts_on->isoWeek.' · '.$period->starts_on->format('d M Y'), 'status' => ucfirst($period->status), 'trips' => $summary['trips'], 'seats' => $summary['seats'],
                'filled' => $summary['filled'], 'open' => $summary['open'], 'rate' => $rate.'%', 'blocking' => $summary['blocking'], 'overrides' => $overrides,
                'built' => $period->built_at?->format('d M Y H:i') ?? 'Not built', '_tone' => array_filter(['open' => $summary['open'] ? 'warning' : null, 'blocking' => $summary['blocking'] ? 'danger' : null])];
            $totals['seats'] += $summary['seats'];
            $totals['filled'] += $summary['filled'];
            $totals['blocking'] += $summary['blocking'];
        }

        return ['description' => 'Seats filled, open and in conflict for each roster week starting in the period.',
            'columns' => [['key' => 'week', 'label' => 'Week'], ['key' => 'status', 'label' => 'Status'], ['key' => 'trips', 'label' => 'Trips', 'align' => 'right'], ['key' => 'seats', 'label' => 'Seats', 'align' => 'right'],
                ['key' => 'filled', 'label' => 'Filled', 'align' => 'right'], ['key' => 'open', 'label' => 'Open', 'align' => 'right'], ['key' => 'rate', 'label' => 'Fill rate', 'align' => 'right'],
                ['key' => 'blocking', 'label' => 'Rule conflicts', 'align' => 'right'], ['key' => 'overrides', 'label' => 'Overrides', 'align' => 'right'], ['key' => 'built', 'label' => 'Last built (UTC)']],
            'rows' => $rows,
            'summary' => ['Weeks' => count($rows), 'Seats' => $totals['seats'], 'Fill rate' => ($totals['seats'] ? round(100 * $totals['filled'] / $totals['seats'], 1) : 0).'%', 'Rule conflicts' => $totals['blocking']],
            'notes' => 'Conflicts are checked live against current crew data. Overrides are seats assigned with a recorded reason.'];
    }

    /** Block and duty hours per crew member for duties reported in the range (published weeks only). */
    private function hours(CarbonImmutable $from, CarbonImmutable $to, ?string $group): array
    {
        $ranks = match ($group) {
            'pilots' => ['CPT', 'FO'],
            'cabin' => ['CC'],
            default => ['CPT', 'FO', 'CC'],
        };
        $now = intdiv(CarbonImmutable::now('UTC')->getTimestamp(), 60);
        $start = $from->format('Y-m-d');
        $end = $to->format('Y-m-d');
        $crews = CrewMember::query()->whereIn('rank', $ranks)->orderByRaw("case rank when 'CPT' then 0 when 'FO' then 1 else 2 end")->orderBy('name')->get();
        $totals = $crews->mapWithKeys(fn (CrewMember $crew): array => [$crew->id => ['trips' => 0, 'block' => 0, 'duty' => 0, 'scheduled' => 0]])->all();
        $assignments = Assignment::query()->whereIn('crew_member_id', $crews->pluck('id'))
            ->whereHas('trip', fn ($query) => $query->whereBetween('start_date', [$from->subDays((int) config('roster.max_trip_days') - 1)->format('Y-m-d'), $end])->whereHas('rosterPeriod', fn ($query) => $query->where('status', 'published')))
            ->with('trip')->get();
        foreach ($assignments as $assignment) {
            $duty = Duty::fromSnapshot('a', $assignment->trip_id, $assignment->trip->schedule_snapshot);
            $counted = false;
            foreach ($duty?->periods ?? [] as $period) {
                if ($period['date'] < $start || $period['date'] > $end) {
                    continue;
                }
                $flown = $period['end'] <= $now;
                $totals[$assignment->crew_member_id][$flown ? 'block' : 'scheduled'] += $period['block'];
                if ($flown) {
                    $totals[$assignment->crew_member_id]['duty'] += $period['end'] - $period['start'];
                }
                if (! $counted) {
                    $totals[$assignment->crew_member_id]['trips']++;
                    $counted = true;
                }
            }
        }
        $weeks = max(1, (int) ceil(($from->diffInDays($to) + 1) / 7));
        $limit = (float) ($this->monthLimit() * 60);
        $rows = $crews->filter(fn (CrewMember $crew): bool => $crew->active || $totals[$crew->id]['trips'] > 0)->values()->map(fn (CrewMember $crew): array => [
            'name' => $crew->name, 'rank' => self::RANKS[$crew->rank], 'base' => $crew->base_airport, 'trips' => $totals[$crew->id]['trips'],
            'block' => Duty::hours($totals[$crew->id]['block']), 'duty' => Duty::hours($totals[$crew->id]['duty']), 'scheduled' => Duty::hours($totals[$crew->id]['scheduled']),
            'per_week' => Duty::hours($totals[$crew->id]['block'] / $weeks), 'working_hours' => $crew->weekly_hours ? $crew->weekly_hours.'h' : '—',
            '_tone' => $totals[$crew->id]['block'] + $totals[$crew->id]['scheduled'] > $limit && $weeks <= 5 ? ['block' => 'warning'] : [],
        ])->all();
        $sum = fn (string $field): int => array_sum(array_column($totals, $field));

        return ['description' => 'Flown block and duty hours from published rosters, with block still scheduled in the period.',
            'columns' => [['key' => 'name', 'label' => 'Crew member'], ['key' => 'rank', 'label' => 'Position'], ['key' => 'base', 'label' => 'Base'], ['key' => 'trips', 'label' => 'Trips', 'align' => 'right'],
                ['key' => 'block', 'label' => 'Block flown', 'align' => 'right'], ['key' => 'duty', 'label' => 'Duty flown', 'align' => 'right'], ['key' => 'scheduled', 'label' => 'Block scheduled', 'align' => 'right'],
                ['key' => 'per_week', 'label' => 'Block per week', 'align' => 'right'], ['key' => 'working_hours', 'label' => 'Working hours']],
            'rows' => $rows,
            'summary' => ['Crew' => count($rows), 'Trips' => $sum('trips'), 'Block flown' => Duty::hours($sum('block')), 'Duty flown' => Duty::hours($sum('duty'))],
            'notes' => 'Duty counts on its base-local report date. Timed SIM and standby are not included here; see Crew hours for duty including them.'];
    }

    /** Every manually set seat in the range: who set it, why, and any rule it overrode. */
    private function overrides(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $seats = Assignment::query()->where('source', 'manual')->whereNotNull('crew_member_id')
            ->whereHas('trip', fn ($query) => $query->whereBetween('start_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))
            ->with(['trip', 'crewMember'])->get()->sortBy(fn (Assignment $seat): string => $seat->trip->start_date->format('Y-m-d').($seat->trip->schedule_snapshot['code'] ?? ''));
        $rows = $seats->values()->map(fn (Assignment $seat): array => [
            'date' => $seat->trip->start_date->format('d M Y'), 'flight' => $seat->trip->schedule_snapshot['code'] ?? '', 'seat' => $seat->rank === 'CC' ? 'CC'.$seat->seat_number : $seat->rank,
            'crew' => $seat->crewMember->name, 'type' => empty($seat->flag_reasons) ? 'Locked (legal)' : 'Override',
            'issues' => implode(' ', $seat->flag_reasons ?? []), 'reason' => $seat->decision_log['reason'] ?? '', 'by' => $seat->decision_log['user'] ?? '',
            'at' => isset($seat->decision_log['at']) ? CarbonImmutable::parse($seat->decision_log['at'])->utc()->format('d M Y H:i') : '',
            '_tone' => empty($seat->flag_reasons) ? [] : ['type' => 'danger'],
        ])->all();

        return ['description' => 'Seats set by a person in the period, including those accepted despite a rule check, with the recorded reason.',
            'columns' => [['key' => 'date', 'label' => 'Date'], ['key' => 'flight', 'label' => 'Flight'], ['key' => 'seat', 'label' => 'Seat'], ['key' => 'crew', 'label' => 'Crew member'], ['key' => 'type', 'label' => 'Type'],
                ['key' => 'issues', 'label' => 'Rules overridden'], ['key' => 'reason', 'label' => 'Reason'], ['key' => 'by', 'label' => 'Set by'], ['key' => 'at', 'label' => 'When (UTC)']],
            'rows' => $rows,
            'summary' => ['Manual seats' => count($rows), 'Overrides' => count(array_filter($rows, fn (array $row): bool => $row['type'] === 'Override'))],
            'notes' => 'Overrides are the seats crew control accepted although they broke a configured rule.'];
    }

    /** Active crew documents expiring on or before the end of the range (expired ones included). */
    private function documents(CarbonImmutable $to): array
    {
        $labels = ['licence' => 'Licence', 'medical' => 'Medical', 'recurrent' => 'Recurrent training'];
        $documents = CrewDocument::query()->whereHas('crewMember', fn ($query) => $query->where('active', true))->where('expires_on', '<=', $to->format('Y-m-d'))->with('crewMember')->orderBy('expires_on')->get();
        $missing = CrewMember::query()->where('active', true)->withCount('documents')->orderBy('name')->get()->filter(fn (CrewMember $crew): bool => $crew->documents_count < 3);
        $rows = $documents->map(function (CrewDocument $document) use ($labels): array {
            $state = $this->expiry->documentState($document->expires_on);
            $days = $this->expiry->daysUntil($document->expires_on);

            return ['crew' => $document->crewMember->name, 'rank' => self::RANKS[$document->crewMember->rank], 'base' => $document->crewMember->base_airport, 'document' => $labels[$document->kind],
                'expires' => $document->expires_on->format('d M Y'), 'days' => $days, 'state' => match ($state) {
                    'expired' => 'Expired', 'due_soon' => 'Expiring soon', default => 'Valid'
                },
                '_tone' => ['state' => $state === 'expired' ? 'danger' : ($state === 'due_soon' ? 'warning' : 'success')]];
        })->all();
        foreach ($missing as $crew) {
            $rows[] = ['crew' => $crew->name, 'rank' => self::RANKS[$crew->rank], 'base' => $crew->base_airport, 'document' => (3 - $crew->documents_count).' not recorded', 'expires' => '—', 'days' => '', 'state' => 'Missing', '_tone' => ['state' => 'danger']];
        }

        return ['description' => 'Licence, medical and recurrent training expiring by the end of the period, plus missing records.',
            'columns' => [['key' => 'crew', 'label' => 'Crew member'], ['key' => 'rank', 'label' => 'Position'], ['key' => 'base', 'label' => 'Base'], ['key' => 'document', 'label' => 'Document'],
                ['key' => 'expires', 'label' => 'Expires'], ['key' => 'days', 'label' => 'Days left', 'align' => 'right'], ['key' => 'state', 'label' => 'State']],
            'rows' => $rows,
            'summary' => ['Expired' => count(array_filter($rows, fn (array $row): bool => $row['state'] === 'Expired')), 'Expiring' => count(array_filter($rows, fn (array $row): bool => $row['state'] !== 'Expired' && $row['state'] !== 'Missing')), 'Missing records' => $missing->count()],
            'notes' => 'Crew with an expired or missing document are not rostered automatically.'];
    }

    /** Every airframe with its status and each maintenance item's next due point. */
    private function maintenanceDue(): array
    {
        $items = $this->maintenance->dueItems()->groupBy(fn (array $item): int => $item['record']->aircraft_id);
        $rows = [];
        foreach (Aircraft::query()->with('aircraftType')->orderBy('registration')->get() as $aircraft) {
            $due = $items->get($aircraft->id, collect());
            if ($due->isEmpty()) {
                $rows[] = ['registration' => $aircraft->registration, 'type' => $aircraft->aircraftType->code, 'status' => Aircraft::STATUSES[$aircraft->status], 'hours' => number_format((float) $aircraft->airframe_hours, 1), 'check' => 'No due items', 'due' => '—', 'state' => '—', '_tone' => $this->statusTone($aircraft->status)];

                continue;
            }
            foreach ($due as $item) {
                $record = $item['record'];
                $dueParts = array_filter([$record->next_due_on?->format('d M Y'), $record->next_due_hours !== null ? number_format((float) $record->next_due_hours, 1).' h' : null]);
                $rows[] = ['registration' => $aircraft->registration, 'type' => $aircraft->aircraftType->code, 'status' => Aircraft::STATUSES[$aircraft->status], 'hours' => number_format((float) $aircraft->airframe_hours, 1),
                    'check' => $record->title, 'due' => implode(' / ', $dueParts), 'state' => match ($item['state']) {
                        'overdue' => 'Overdue', 'due_soon' => 'Due soon', default => 'Current'
                    },
                    '_tone' => [...$this->statusTone($aircraft->status), 'state' => match ($item['state']) {
                        'overdue' => 'danger', 'due_soon' => 'warning', default => 'success'
                    }]];
            }
        }
        $fleet = Aircraft::query()->toBase()->select('status')->selectRaw('count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['description' => 'Airframe status and the next due point of each maintenance check (latest record per check type).',
            'columns' => [['key' => 'registration', 'label' => 'Registration'], ['key' => 'type', 'label' => 'Type'], ['key' => 'status', 'label' => 'Status'], ['key' => 'hours', 'label' => 'Airframe hours', 'align' => 'right'],
                ['key' => 'check', 'label' => 'Check'], ['key' => 'due', 'label' => 'Next due'], ['key' => 'state', 'label' => 'State']],
            'rows' => $rows,
            'summary' => ['Airframes' => (int) $fleet->sum(), 'Available' => (int) ($fleet['available'] ?? 0), 'Overdue' => count(array_filter($rows, fn (array $row): bool => $row['state'] === 'Overdue')), 'Due soon' => count(array_filter($rows, fn (array $row): bool => $row['state'] === 'Due soon'))],
            'notes' => 'Today at base: '.$this->expiry->today()->format('d M Y').'. Hours-based items compare with the airframe hours on record.'];
    }

    /** Trips, seats and hours per flight pattern for trips starting in the range (all weeks). */
    private function flights(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $trips = Trip::query()->whereBetween('start_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])->with(['assignments', 'flight.aircraftType', 'rosterPeriod'])->get()->groupBy('flight_id');
        $rows = $trips->map(function ($group): array {
            $flight = $group->first()->flight;
            $seats = $group->sum(fn (Trip $trip): int => $trip->assignments->count());
            $filled = $group->sum(fn (Trip $trip): int => $trip->assignments->whereNotNull('crew_member_id')->count());
            $block = $group->sum(fn (Trip $trip): int => (int) ($trip->schedule_snapshot['block_minutes'] ?? 0));

            return ['code' => $flight->code, 'aircraft' => $flight->aircraftType->code, 'state' => $flight->active ? 'Enabled' : 'Disabled', 'trips' => $group->count(),
                'published' => $group->filter(fn (Trip $trip): bool => $trip->rosterPeriod->status === 'published')->count(), 'seats' => $seats, 'filled' => $filled,
                'rate' => ($seats ? round(100 * $filled / $seats, 1) : 0).'%', 'block' => Duty::hours($block), 'crew_block' => Duty::hours($block * $filled),
                '_tone' => $flight->active ? [] : ['state' => 'warning']];
        })->sortBy('code')->values()->all();

        return ['description' => 'How each flight pattern was rostered: trips, seats filled and block hours (aircraft and crew).',
            'columns' => [['key' => 'code', 'label' => 'Flight'], ['key' => 'aircraft', 'label' => 'Aircraft'], ['key' => 'state', 'label' => 'Pattern'], ['key' => 'trips', 'label' => 'Trips', 'align' => 'right'],
                ['key' => 'published', 'label' => 'Published', 'align' => 'right'], ['key' => 'seats', 'label' => 'Seats', 'align' => 'right'], ['key' => 'filled', 'label' => 'Filled', 'align' => 'right'],
                ['key' => 'rate', 'label' => 'Fill rate', 'align' => 'right'], ['key' => 'block', 'label' => 'Aircraft block', 'align' => 'right'], ['key' => 'crew_block', 'label' => 'Crew block', 'align' => 'right']],
            'rows' => $rows,
            'summary' => ['Patterns' => count($rows), 'Trips' => array_sum(array_column($rows, 'trips')), 'Seats filled' => array_sum(array_column($rows, 'filled')).' / '.array_sum(array_column($rows, 'seats'))],
            'notes' => 'Includes draft and published weeks. Crew block is aircraft block multiplied by filled seats.'];
    }

    /** Audit entries in the range (newest first, at most 2,000), without the before/after snapshots. */
    private function audit(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $entries = AuditLog::query()->whereBetween('created_at', [$from->startOfDay()->format('Y-m-d H:i:s'), $to->endOfDay()->format('Y-m-d H:i:s')])->with('user')->latest('id')->limit(2000)->get();
        $rows = $entries->map(fn (AuditLog $entry): array => [
            'at' => $entry->created_at?->utc()->format('d M Y H:i'), 'user' => $entry->user?->name ?? 'Deleted user', 'action' => str_replace('_', ' ', $entry->action),
            'entity' => str_replace('_', ' ', $entry->entity), 'id' => $entry->entity_id,
        ])->all();

        return ['description' => 'Who changed what, from the transactional audit log.',
            'columns' => [['key' => 'at', 'label' => 'When (UTC)'], ['key' => 'user', 'label' => 'User'], ['key' => 'action', 'label' => 'Action'], ['key' => 'entity', 'label' => 'Record type'], ['key' => 'id', 'label' => 'Record', 'align' => 'right']],
            'rows' => $rows,
            'summary' => ['Entries' => count($rows), 'People' => $entries->pluck('user_id')->filter()->unique()->count()],
            'notes' => 'At most 2,000 entries per report; narrow the period for more detail.'];
    }

    /**
     * Status column tone for an airframe.
     *
     * @return array<string, string>
     */
    private function statusTone(string $status): array
    {
        return match ($status) {
            'available' => ['status' => 'success'],
            'grounded' => ['status' => 'danger'],
            'maintenance' => ['status' => 'warning'],
            default => [],
        };
    }

    /** The standard rule set's monthly block limit in hours. */
    private function monthLimit(): float
    {
        return (float) (RuleSet::query()->whereKey(1)->value('max_block_month_h') ?? 100);
    }
}
