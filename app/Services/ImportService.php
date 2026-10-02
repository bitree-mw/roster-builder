<?php

namespace App\Services;

use App\Models\AircraftType;
use App\Models\Airport;
use App\Models\CrewActivity;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\RuleSet;
use App\Models\User;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CSV import of crew members, flight patterns and day planning (leave, day off, SIM, standby).
 *
 * 1. preview(): read the file (comma, semicolon or tab; header aliases; flexible dates including Excel
 *    serial numbers), check every row against current data and return what would happen to each one:
 *    create, update, unchanged, deactivate/disable/delete (replace mode) or error. Nothing is saved; the
 *    checked rows are cached for 30 minutes under a token tied to the user.
 * 2. commit(): only when no row has an error, and only if the affected data has not changed since the
 *    preview (otherwise preview again). Everything is applied in one transaction through the normal
 *    services, so every change is validated and audited as if it had been typed in.
 *
 * Modes: "merge" only adds and updates. "replace" also deactivates crew missing from the file, disables
 * flights missing from it, or (day planning) replaces each listed crew member's own planning in the date
 * range the file covers for them. Crew are matched by name and flights by code; a name shared by two crew
 * members is an error rather than a guess.
 */
class ImportService
{
    /** Import kinds with their labels. */
    public const KINDS = ['crew' => 'Crew members', 'flights' => 'Flight patterns', 'activities' => 'Leave and day planning'];

    /** Accepted header names per kind (normalised: lowercase letters and digits only). */
    private const COLUMNS = [
        'crew' => [
            'name' => ['name', 'fullname', 'crewname', 'crewmember'], 'email' => ['email', 'emailaddress', 'mail'],
            'rank' => ['rank', 'position', 'role', 'function'], 'base' => ['base', 'baseairport', 'homebase', 'station'],
            'aircraft' => ['aircraft', 'ratings', 'rating', 'aircrafttypes', 'types'], 'weekly_hours' => ['weeklyhours', 'workinghours', 'hoursperweek', 'contracthours'],
            'active' => ['active', 'status', 'enabled'], 'licence' => ['licence', 'license', 'licenceexpiry', 'licenseexpiry'],
            'medical' => ['medical', 'medicalexpiry'], 'recurrent' => ['recurrent', 'recurrentexpiry', 'recurrenttraining'],
        ],
        'flights' => [
            'code' => ['code', 'flight', 'flightcode', 'pattern'], 'aircraft' => ['aircraft', 'aircrafttype', 'type', 'equipment'],
            'days' => ['days', 'operatingdays', 'weekdays', 'frequency'], 'legs' => ['legs', 'sectors', 'routing', 'schedule'], 'active' => ['active', 'status', 'enabled'],
        ],
        'activities' => [
            'crew' => ['crew', 'name', 'crewmember', 'fullname'], 'type' => ['type', 'activity', 'code', 'kind'],
            'from' => ['from', 'date', 'start', 'startdate', 'datefrom'], 'to' => ['to', 'end', 'enddate', 'dateto', 'until'],
            'starts' => ['starts', 'starttime', 'timefrom', 'begin'], 'ends' => ['ends', 'endtime', 'timeto', 'finish'], 'note' => ['note', 'notes', 'comment', 'remarks'],
        ],
    ];

    private const REQUIRED = ['crew' => ['name', 'rank', 'base'], 'flights' => ['code', 'aircraft', 'days', 'legs'], 'activities' => ['crew', 'type', 'from']];

    /** Example rows for the downloadable templates. */
    private const TEMPLATES = [
        'crew' => [['Name', 'Email', 'Rank', 'Base', 'Aircraft', 'Weekly hours', 'Active', 'Licence', 'Medical', 'Recurrent'], ['Chikondi Phiri', 'c.phiri@example.com', 'CPT', 'LLW', 'Q400', '40', 'yes', '2027-03-31', '2026-12-15', '2027-01-20']],
        'flights' => [['Code', 'Aircraft', 'Days', 'Legs', 'Active'], ['LB1', 'Q400', 'Mon Wed Fri', 'LLW-BLZ 08:00-09:00; BLZ-LLW 09:40-10:40', 'yes'], ['LA1', 'B737', '1,3,5', 'LLW-ADD 12:00-16:00; D2 ADD-LLW 08:00-12:00', 'yes']],
        'activities' => [['Crew', 'Type', 'From', 'To', 'Starts', 'Ends', 'Note'], ['Chikondi Phiri', 'Leave', '2026-10-12', '2026-10-16', '', '', 'Annual leave'], ['Chikondi Phiri', 'SIM', '2026-10-20', '', '06:00', '10:00', 'Recurrent check']],
    ];

    /** Longest day-planning range per row, in days. */
    private const MAX_RANGE_DAYS = 31;

    public function __construct(
        private AuditService $audit,
        private CrewMemberService $crewService,
        private FlightService $flightService,
        private FlightTimelineService $timeline,
        private CrewActivityService $activities,
    ) {}

    /** A template CSV with every column and example rows. */
    public function template(string $kind): string
    {
        [$headers, $example] = [self::TEMPLATES[$kind][0], array_slice(self::TEMPLATES[$kind], 1)];

        return Csv::write($headers, $example);
    }

    /**
     * Check a file and cache the result for commit().
     *
     * @param  array{times?: string}  $options  flights: "local" (default) or "utc" leg times
     * @return array{token: string, kind: string, mode: string, rows: array<int, array<string, mixed>>, summary: array<string, int>, delimiter: string}
     *
     * @throws ValidationException when the file is empty, too long or misses required columns
     */
    public function preview(string $kind, string $mode, string $text, array $options, User $actor): array
    {
        $csv = Csv::read($text);
        if ($csv['rows'] === []) {
            throw ValidationException::withMessages(['file' => 'The file has no data rows.']);
        }
        if (count($csv['rows']) > 5000) {
            throw ValidationException::withMessages(['file' => 'Import at most 5,000 rows at a time.']);
        }
        $map = [];
        foreach (self::COLUMNS[$kind] as $column => $aliases) {
            $header = collect($csv['headers'])->first(fn (string $header): bool => in_array($header, $aliases, true));
            if ($header !== null) {
                $map[$column] = $header;
            }
        }
        $missing = array_diff(self::REQUIRED[$kind], array_keys($map));
        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => 'Missing column(s): '.implode(', ', $missing).'. Download the template to see the expected headings.']);
        }
        $records = array_map(fn (array $row): array => ['line' => (int) $row['_line'], 'values' => collect($map)->map(fn (string $header): string => $row[$header] ?? '')->all()], $csv['rows']);
        $rows = match ($kind) {
            'crew' => $this->crewRows($records, $mode),
            'flights' => $this->flightRows($records, $mode, $options['times'] ?? 'local'),
            'activities' => $this->activityRows($records, $mode),
        };
        $token = (string) Str::uuid();
        Cache::put('import:'.$token, ['user_id' => $actor->id, 'kind' => $kind, 'mode' => $mode, 'rows' => $rows, 'fingerprint' => $this->fingerprint($kind)], now()->addMinutes(30));

        return ['token' => $token, 'kind' => $kind, 'mode' => $mode, 'delimiter' => $csv['delimiter'] === "\t" ? 'tab' : $csv['delimiter'],
            'rows' => array_map(fn (array $row): array => collect($row)->except('data')->all(), $rows), 'summary' => collect($rows)->countBy('action')->all()];
    }

    /**
     * Apply a previewed import.
     *
     * @return array{kind: string, summary: array<string, int>}
     *
     * @throws ValidationException when the preview is missing, has errors or is out of date
     */
    public function commit(string $token, User $actor): array
    {
        $preview = Cache::get('import:'.$token);
        if ($preview === null || $preview['user_id'] !== $actor->id) {
            throw ValidationException::withMessages(['token' => 'This preview has expired. Choose the file and preview it again.']);
        }
        $errors = collect($preview['rows'])->where('action', 'error')->count();
        if ($errors > 0) {
            throw ValidationException::withMessages(['token' => 'Fix the '.$errors.' row(s) with errors in the file, then preview it again.']);
        }
        if ($this->fingerprint($preview['kind']) !== $preview['fingerprint']) {
            Cache::forget('import:'.$token);
            throw ValidationException::withMessages(['token' => 'The data changed since this preview (someone else may have edited it). Preview the file again.']);
        }
        DB::transaction(function () use ($preview, $actor): void {
            foreach ($preview['rows'] as $row) {
                $this->apply($preview['kind'], $row, $actor);
            }
            $this->audit->event($actor, 'imported', 'imports', ['kind' => $preview['kind'], 'mode' => $preview['mode'], 'summary' => collect($preview['rows'])->countBy('action')->all()]);
        });
        Cache::forget('import:'.$token);

        return ['kind' => $preview['kind'], 'summary' => collect($preview['rows'])->countBy('action')->all()];
    }

    /**
     * Crew rows: matched by name; ratings by aircraft code ("ALL" for cabin crew); blank cells keep the
     * current value on update.
     *
     * @param  array<int, array{line: int, values: array<string, string>}>  $records
     * @return array<int, array<string, mixed>>
     */
    private function crewRows(array $records, string $mode): array
    {
        $crews = CrewMember::query()->with(['ratings', 'documents'])->get();
        $byName = $crews->groupBy(fn (CrewMember $crew): string => $this->key($crew->name));
        $types = AircraftType::query()->get()->keyBy(fn (AircraftType $type): string => mb_strtoupper($type->code));
        $bases = Airport::query()->where('is_base', true)->pluck('code')->all();
        $seen = [];
        $rows = [];
        foreach ($records as ['line' => $line, 'values' => $values]) {
            $name = preg_replace('/\s+/', ' ', trim($values['name'])) ?? '';
            $messages = [];
            $key = $this->key($name);
            if ($name === '') {
                $rows[] = $this->row($line, 'error', '(no name)', ['Name is required.']);

                continue;
            }
            if (isset($seen[$key])) {
                $rows[] = $this->row($line, 'error', $name, ['Also on line '.$seen[$key].' of the file.']);

                continue;
            }
            $seen[$key] = $line;
            $matches = $byName->get($key, collect());
            if ($matches->count() > 1) {
                $rows[] = $this->row($line, 'error', $name, [$matches->count().' crew members are called '.$name.'; rename one before importing.']);

                continue;
            }
            $existing = $matches->first();
            $rank = $this->rank($values['rank']);
            if ($rank === null) {
                $messages[] = 'Rank "'.$values['rank'].'" is not CPT, FO or CC.';
            }
            $base = mb_strtoupper(trim($values['base']));
            if (! in_array($base, $bases, true)) {
                $messages[] = 'Base "'.$values['base'].'" is not a crew base.';
            }
            $email = trim($values['email'] ?? '');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $messages[] = 'Email "'.$email.'" is not valid.';
            }
            // Ratings: "ALL" (cabin crew only), a list of type codes, or blank (keep what is on record; new
            // cabin crew default to all aircraft).
            $aircraft = trim($values['aircraft'] ?? '');
            if (mb_strtoupper($aircraft) === 'ALL') {
                [$allAircraft, $ratingIds] = [true, []];
            } elseif ($aircraft !== '') {
                $codes = preg_split('/[\s,;|\/]+/', mb_strtoupper($aircraft), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $unknown = array_diff($codes, $types->keys()->all());
                if ($unknown !== []) {
                    $messages[] = 'Unknown aircraft type(s): '.implode(', ', $unknown).'.';
                }
                [$allAircraft, $ratingIds] = [false, collect($codes)->map(fn (string $code): ?int => $types->get($code)?->id)->filter()->values()->all()];
            } else {
                [$allAircraft, $ratingIds] = [$existing?->all_aircraft ?? $rank === 'CC', $existing?->ratings->pluck('id')->all() ?? []];
            }
            if ($allAircraft && $rank !== null && $rank !== 'CC') {
                $messages[] = 'Only cabin crew can be rated on all aircraft; list the pilot\'s aircraft types.';
            } elseif (! $allAircraft && $ratingIds === [] && $aircraft === '') {
                $messages[] = 'Give at least one aircraft rating (for example Q400), or ALL for cabin crew.';
            }
            $weekly = trim($values['weekly_hours'] ?? '');
            $weeklyHours = $existing?->weekly_hours;
            if ($weekly !== '') {
                $weeklyHours = ctype_digit($weekly) && (int) $weekly >= 1 && (int) $weekly <= 168 ? (int) $weekly : null;
                if ($weeklyHours === null) {
                    $messages[] = 'Weekly hours must be a whole number from 1 to 168.';
                }
            }
            $active = $this->boolean($values['active'] ?? '', $existing?->active ?? true);
            if ($active === null) {
                $messages[] = 'Active must be yes or no.';
            }
            // Documents: given dates replace that kind; blank keeps what is on record.
            $documents = $existing ? $existing->documents->mapWithKeys(fn ($document): array => [$document->kind => $document->expires_on->format('Y-m-d')])->all() : [];
            foreach (['licence', 'medical', 'recurrent'] as $kind) {
                $value = trim($values[$kind] ?? '');
                if ($value === '') {
                    continue;
                }
                $date = Csv::date($value);
                if ($date === null) {
                    $messages[] = ucfirst($kind).' date "'.$value.'" is not a date (use YYYY-MM-DD or DD/MM/YYYY).';
                } else {
                    $documents[$kind] = $date;
                }
            }
            if ($messages !== []) {
                $rows[] = $this->row($line, 'error', $name, $messages);

                continue;
            }
            ksort($documents);
            // Names are the matching key: an existing crew member keeps the spelling on record.
            $payload = ['name' => $existing?->name ?? $name, 'email' => $email !== '' ? $email : $existing?->email, 'rank' => $rank, 'base_airport' => $base, 'active' => $active, 'all_aircraft' => $allAircraft,
                'weekly_hours' => $weeklyHours, 'rating_ids' => $ratingIds, 'documents' => collect($documents)->map(fn (string $date, string $kind): array => ['kind' => $kind, 'expires_on' => $date])->values()->all()];
            $action = $existing === null ? 'create' : ($this->sameCrew($existing, $payload) ? 'unchanged' : 'update');
            $rows[] = $this->row($line, $action, $name, $existing && $rank !== $existing->rank ? ['Position changes from '.$existing->rank.' to '.$rank.'.'] : [], ['crew_member_id' => $existing?->id, 'payload' => $payload]);
        }
        if ($mode === 'replace') {
            foreach ($crews as $crew) {
                if ($crew->active && ! isset($seen[$this->key($crew->name)])) {
                    $rows[] = $this->row(0, 'deactivate', $crew->name, ['Not in the file: will be marked inactive (history is kept).'], ['crew_member_id' => $crew->id]);
                }
            }
        }

        return $rows;
    }

    /**
     * Flight rows: matched by code; days as names (Mon Wed Fri), numbers (1,3,5 with Monday = 1), a seven
     * character mask (1.1.1.. or MTWTFSS) or "daily"; legs as "LLW-BLZ 08:00-09:00; D2 BLZ-LLW 08:00-09:00".
     * Each pattern is validated against the duty rules exactly like the flight editor.
     *
     * @param  array<int, array{line: int, values: array<string, string>}>  $records
     * @return array<int, array<string, mixed>>
     */
    private function flightRows(array $records, string $mode, string $times): array
    {
        $flights = Flight::query()->with(['legs', 'days'])->get()->keyBy(fn (Flight $flight): string => mb_strtoupper($flight->code));
        $types = AircraftType::query()->get()->keyBy(fn (AircraftType $type): string => mb_strtoupper($type->code));
        $airports = Airport::query()->pluck('code')->flip();
        $rules = RuleSet::query()->findOrFail(1);
        $seen = [];
        $rows = [];
        foreach ($records as ['line' => $line, 'values' => $values]) {
            $code = mb_strtoupper(trim($values['code']));
            $messages = [];
            if (! preg_match('/^[A-Z0-9-]{1,20}$/', $code)) {
                $rows[] = $this->row($line, 'error', $code ?: '(no code)', ['Flight code must be 1–20 letters, digits or dashes.']);

                continue;
            }
            if (isset($seen[$code])) {
                $rows[] = $this->row($line, 'error', $code, ['Also on line '.$seen[$code].' of the file.']);

                continue;
            }
            $seen[$code] = $line;
            $type = $types->get(mb_strtoupper(trim($values['aircraft'])));
            if ($type === null) {
                $messages[] = 'Unknown aircraft type "'.$values['aircraft'].'".';
            }
            $weekdays = $this->weekdays($values['days']);
            if ($weekdays === null) {
                $messages[] = 'Days "'.$values['days'].'" not understood (use e.g. "Mon Wed Fri", "1,3,5" or "1.1.1..").';
            }
            $legs = $this->legs($values['legs'], $airports, $times === 'utc' ? (int) $rules->utc_offset_minutes : 0, $messages);
            $active = $this->boolean($values['active'] ?? '', $flights->get($code)?->active ?? true);
            if ($active === null) {
                $messages[] = 'Active must be yes or no.';
            }
            if ($messages === [] && $legs !== []) {
                try {
                    $this->timeline->validate($legs, $rules);
                } catch (ValidationException $exception) {
                    $messages[] = collect($exception->errors())->flatten()->first();
                }
            }
            if ($messages !== []) {
                $rows[] = $this->row($line, 'error', $code, $messages);

                continue;
            }
            $existing = $flights->get($code);
            $payload = ['code' => $code, 'aircraft_type_id' => $type->id, 'active' => $active, 'weekdays' => $weekdays, 'legs' => $legs];
            $action = $existing === null ? 'create' : ($this->sameFlight($existing, $payload) ? 'unchanged' : 'update');
            $rows[] = $this->row($line, $action, $code, $times === 'utc' ? ['Leg times converted from UTC to base local time.'] : [], ['flight_id' => $existing?->id, 'payload' => $payload]);
        }
        if ($mode === 'replace') {
            foreach ($flights as $code => $flight) {
                if ($flight->active && ! isset($seen[$code])) {
                    $rows[] = $this->row(0, 'disable', $flight->code, ['Not in the file: will be disabled (kept on file, not planned).'], ['flight_id' => $flight->id]);
                }
            }
        }

        return $rows;
    }

    /**
     * Day planning rows: crew matched by name; type leave, day off, SIM or standby (common abbreviations
     * accepted); a date or date range (31 days at most); optional base-local times for SIM and standby.
     *
     * @param  array<int, array{line: int, values: array<string, string>}>  $records
     * @return array<int, array<string, mixed>>
     */
    private function activityRows(array $records, string $mode): array
    {
        $byName = CrewMember::query()->get()->groupBy(fn (CrewMember $crew): string => $this->key($crew->name));
        $planned = [];
        $ranges = [];
        $rows = [];
        foreach ($records as ['line' => $line, 'values' => $values]) {
            $name = preg_replace('/\s+/', ' ', trim($values['crew'])) ?? '';
            $matches = $byName->get($this->key($name), collect());
            $messages = [];
            if ($matches->count() !== 1) {
                $rows[] = $this->row($line, 'error', $name ?: '(no crew)', [$matches->isEmpty() ? 'No crew member called "'.$name.'".' : $matches->count().' crew members are called '.$name.'.']);

                continue;
            }
            $crew = $matches->first();
            $type = $this->activityType($values['type']);
            if ($type === null) {
                $messages[] = 'Type "'.$values['type'].'" is not leave, day off, SIM or standby.';
            }
            $from = Csv::date($values['from']);
            $to = trim($values['to'] ?? '') === '' ? $from : Csv::date($values['to']);
            if ($from === null || $to === null) {
                $messages[] = 'Dates must be like 2026-10-12 or 12/10/2026.';
            } elseif ($to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_RANGE_DAYS - 1) {
                $messages[] = 'The end date must be on or after the start, and at most 31 days later.';
            }
            $starts = trim($values['starts'] ?? '') === '' ? null : Csv::time($values['starts']);
            $ends = trim($values['ends'] ?? '') === '' ? null : Csv::time($values['ends']);
            if ((trim($values['starts'] ?? '') !== '' && $starts === null) || (trim($values['ends'] ?? '') !== '' && $ends === null) || ($starts === null) !== ($ends === null)) {
                $messages[] = 'Give both start and end times as HH:MM, or neither.';
            }
            if ($starts !== null && in_array($type, ['leave', 'day_off'], true)) {
                $messages[] = 'Leave and days off are whole days; remove the times.';
            }
            if ($messages === []) {
                for ($date = $from; $date <= $to; $date = CarbonImmutable::parse($date)->addDay()->format('Y-m-d')) {
                    if (isset($planned[$crew->id][$date])) {
                        $messages[] = CarbonImmutable::parse($date)->format('d M').' is also on line '.$planned[$crew->id][$date].'.';
                    }
                    $planned[$crew->id][$date] = $line;
                }
                $ranges[$crew->id] = [min($ranges[$crew->id][0] ?? $from, $from), max($ranges[$crew->id][1] ?? $to, $to)];
            }
            if ($messages !== []) {
                $rows[] = $this->row($line, 'error', $name, $messages);

                continue;
            }
            $rows[] = $this->row($line, 'create', $name.' · '.CrewActivityService::LABELS[$type].' '.CarbonImmutable::parse($from)->format('d M').($to !== $from ? '–'.CarbonImmutable::parse($to)->format('d M') : ''), [],
                ['crew_member_id' => $crew->id, 'type' => $type, 'from' => $from, 'to' => $to, 'starts_local' => $starts, 'ends_local' => $ends, 'note' => trim($values['note'] ?? '') ?: null]);
        }
        // Existing items on the same days: replaced in replace mode, otherwise the row is an error.
        foreach ($ranges as $crewId => [$start, $end]) {
            $existing = CrewActivity::query()->where('crew_member_id', $crewId)->whereBetween('date', [$start, $end])->orderBy('date')->get();
            foreach ($existing as $activity) {
                $date = $activity->date->format('Y-m-d');
                if ($mode === 'replace') {
                    $rows[] = $this->row(0, 'delete', $activity->crewMember()->value('name').' · '.CrewActivityService::LABELS[$activity->type].' '.$activity->date->format('d M'), ['Replaced by this file.'], ['activity_id' => $activity->id]);
                } elseif (isset($planned[$crewId][$date])) {
                    foreach ($rows as &$row) {
                        if ($row['line'] === $planned[$crewId][$date] && $row['action'] !== 'error') {
                            $row['action'] = 'error';
                            $row['messages'][] = $activity->date->format('d M').' already has '.mb_strtolower(CrewActivityService::LABELS[$activity->type]).' planned (use replace mode to overwrite).';
                        }
                    }
                    unset($row);
                }
            }
        }

        return $rows;
    }

    /**
     * Apply one previewed row inside the commit transaction.
     *
     * @param  array<string, mixed>  $row
     */
    private function apply(string $kind, array $row, User $actor): void
    {
        $data = $row['data'] ?? [];
        match ([$kind, $row['action']]) {
            ['crew', 'create'] => $this->crewService->save($data['payload'], $actor),
            ['crew', 'update'] => $this->crewService->save($data['payload'], $actor, CrewMember::query()->findOrFail($data['crew_member_id'])),
            ['crew', 'deactivate'] => $this->deactivate(CrewMember::query()->findOrFail($data['crew_member_id']), $actor),
            ['flights', 'create'] => $this->flightService->save($data['payload'], $actor),
            ['flights', 'update'] => $this->flightService->save($data['payload'], $actor, Flight::query()->findOrFail($data['flight_id'])),
            ['flights', 'disable'] => $this->flightService->setActive(Flight::query()->findOrFail($data['flight_id']), false, $actor),
            ['activities', 'delete'] => ($activity = CrewActivity::query()->find($data['activity_id'])) ? $this->activities->delete($activity, $actor) : null,
            ['activities', 'create'] => $this->createActivities($data, $actor),
            default => null,
        };
    }

    /** Mark a crew member inactive (replace mode); history is kept. */
    private function deactivate(CrewMember $crew, User $actor): void
    {
        $before = $crew->toArray();
        $crew->active = false;
        $crew->save();
        $this->audit->record($actor, 'updated', $crew, $before, $crew->toArray());
    }

    /**
     * Create one activity per date of an imported range (deletions of replaced items run first because
     * they are previewed before nothing else touches those days).
     *
     * @param  array<string, mixed>  $data
     */
    private function createActivities(array $data, User $actor): void
    {
        // Replaced items are listed after the new rows, so clear any still on these days first.
        CrewActivity::query()->where('crew_member_id', $data['crew_member_id'])->whereBetween('date', [$data['from'], $data['to']])->get()
            ->each(fn (CrewActivity $activity) => $this->activities->delete($activity, $actor));
        $this->activities->create(['crew_member_id' => $data['crew_member_id'], 'type' => $data['type'], 'date_from' => $data['from'], 'date_to' => $data['to'],
            'starts_local' => $data['starts_local'], 'ends_local' => $data['ends_local'], 'note' => $data['note']], $actor);
    }

    /**
     * A cheap signature of the data an import kind reads, to detect edits between preview and commit.
     */
    private function fingerprint(string $kind): string
    {
        $tables = match ($kind) {
            'crew' => ['crew_members', 'crew_documents', 'aircraft_types'],
            'flights' => ['flights', 'flight_legs', 'aircraft_types'],
            'activities' => ['crew_activities', 'crew_members'],
        };

        return md5(json_encode(array_map(fn (string $table): array => [$table, DB::table($table)->count(), DB::table($table)->max('updated_at')], $tables)));
    }

    /**
     * One preview row.
     *
     * @param  array<int, string>  $messages
     * @param  array<string, mixed>  $data  what commit() applies (kept server-side only)
     * @return array<string, mixed>
     */
    private function row(int $line, string $action, string $label, array $messages = [], array $data = []): array
    {
        return ['line' => $line, 'action' => $action, 'label' => $label, 'messages' => $messages, 'data' => $data];
    }

    /** Case- and space-insensitive name key for matching. */
    private function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
    }

    /** "Captain" / "CPT" / "PIC" -> CPT, "First officer" / "FO" -> FO, "Cabin crew" / "CC" / "FA" -> CC. */
    private function rank(string $value): ?string
    {
        return match (Csv::normalise($value)) {
            'cpt', 'capt', 'captain', 'pic', 'commander' => 'CPT',
            'fo', 'firstofficer', 'copilot', 'sic', 'fofficer' => 'FO',
            'cc', 'cabin', 'cabincrew', 'fa', 'flightattendant', 'purser', 'scc', 'seniorcabincrew' => 'CC',
            default => null,
        };
    }

    /** Leave / AL, day off / DO / OFF, SIM / training, standby / SBY / reserve. */
    private function activityType(string $value): ?string
    {
        return match (Csv::normalise($value)) {
            'leave', 'al', 'annualleave', 'vacation', 'holiday' => 'leave',
            'dayoff', 'do', 'off', 'rest', 'protected', 'protecteddayoff' => 'day_off',
            'sim', 'simulator', 'training', 'opc', 'lpc', 'simulatorsession' => 'sim',
            'standby', 'sby', 'reserve', 'res' => 'standby',
            default => null,
        };
    }

    /** yes/no style values; blank returns the default; anything else null. */
    private function boolean(string $value, bool $default): ?bool
    {
        return match (Csv::normalise($value)) {
            '' => $default,
            'yes', 'y', 'true', '1', 'active', 'enabled', 'on' => true,
            'no', 'n', 'false', '0', 'inactive', 'disabled', 'off' => false,
            default => null,
        };
    }

    /**
     * Operating weekdays (Monday = 0) from names, Monday-based numbers, a seven-character mask or "daily".
     *
     * @return array<int, int>|null
     */
    private function weekdays(string $value): ?array
    {
        $value = trim($value);
        $names = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];
        if (in_array(mb_strtolower($value), ['daily', 'every day', 'all'], true)) {
            return range(0, 6);
        }
        // Seven-character masks: "1.1.1..", "X.X.X..", "1010100" or "MTWTF.." (a dot, dash, space or 0 is a day off).
        if (preg_match('/^[01.xX\-_ MTWFSmtwfs]{7}$/', $value)) {
            $days = [];
            foreach (mb_str_split($value) as $index => $character) {
                if (! in_array($character, ['.', '-', '_', ' ', '0'], true)) {
                    $days[] = $index;
                }
            }

            return $days === [] ? null : $days;
        }
        $tokens = preg_split('/[\s,;\/|]+/', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $days = [];
        foreach ($tokens as $token) {
            if (ctype_digit($token) && (int) $token >= 1 && (int) $token <= 7) {
                $days[] = (int) $token - 1;
            } elseif (isset($names[substr($token, 0, 3)])) {
                $days[] = $names[substr($token, 0, 3)];
            } else {
                return null;
            }
        }
        $days = array_values(array_unique($days));
        sort($days);

        return $days === [] ? null : $days;
    }

    /**
     * Legs from "LLW-BLZ 08:00-09:00; D2 BLZ-LLW 08:00-09:00". A leg without a day prefix continues the
     * previous leg's trip day. With a UTC offset, times are converted from UTC to base local time.
     *
     * @param  Collection<string, int>  $airports  known airport codes (flipped)
     * @param  array<int, string>  $messages  receives problems
     * @return array<int, array{trip_day: int, from_airport: string, to_airport: string, departs_local: string, arrives_local: string}>
     */
    private function legs(string $value, Collection $airports, int $offset, array &$messages): array
    {
        $legs = [];
        $day = 1;
        foreach (preg_split('/[;\n]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $index => $text) {
            if (! preg_match('/^\s*(?:d(?:ay)?\s*(\d)\s+)?([A-Za-z]{3,4})\s*(?:-|–|>|to)\s*([A-Za-z]{3,4})\s+(\d{1,2}:?\d{2})\s*(?:-|–)\s*(\d{1,2}:?\d{2})\s*$/u', $text, $match)) {
                $messages[] = 'Leg '.($index + 1).' "'.trim($text).'" should look like "LLW-BLZ 08:00-09:00" (prefix "D2" for a later trip day).';

                continue;
            }
            $day = $match[1] !== '' ? (int) $match[1] : $day;
            [$from, $to] = [mb_strtoupper($match[2]), mb_strtoupper($match[3])];
            foreach ([$from, $to] as $code) {
                if (! $airports->has($code)) {
                    $messages[] = 'Unknown airport '.$code.' in leg '.($index + 1).'.';
                }
            }
            [$departs, $arrives] = [Csv::time($match[4]), Csv::time($match[5])];
            if ($departs === null || $arrives === null) {
                $messages[] = 'Leg '.($index + 1).' has a time that is not HH:MM.';

                continue;
            }
            $legs[] = ['trip_day' => $day, 'from_airport' => $from, 'to_airport' => $to, 'departs_local' => $this->shift($departs, $offset), 'arrives_local' => $this->shift($arrives, $offset)];
        }
        if ($legs === [] && $messages === []) {
            $messages[] = 'At least one leg is required.';
        }

        return $messages === [] ? $legs : [];
    }

    /** "HH:MM" moved by an offset in minutes, wrapping round midnight (UTC entry -> base local time). */
    private function shift(string $time, int $offset): string
    {
        $minutes = (((int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2) + $offset) % 1440 + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Whether an existing crew member already matches the imported payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sameCrew(CrewMember $crew, array $payload): bool
    {
        $documents = $crew->documents->sortBy('kind')->map(fn ($document): array => ['kind' => $document->kind, 'expires_on' => $document->expires_on->format('Y-m-d')])->values()->all();
        $ratings = $crew->ratings->pluck('id')->sort()->values()->all();
        $wanted = collect($payload['rating_ids'])->sort()->values()->all();

        return $crew->name === $payload['name'] && $crew->email === $payload['email'] && $crew->rank === $payload['rank'] && $crew->base_airport === $payload['base_airport']
            && $crew->active === $payload['active'] && $crew->all_aircraft === $payload['all_aircraft'] && $crew->weekly_hours === $payload['weekly_hours']
            && $ratings === $wanted && $documents === $payload['documents'];
    }

    /**
     * Whether an existing flight already matches the imported payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sameFlight(Flight $flight, array $payload): bool
    {
        $legs = $flight->legs->map(fn ($leg): array => ['trip_day' => $leg->trip_day, 'from_airport' => $leg->from_airport, 'to_airport' => $leg->to_airport, 'departs_local' => substr($leg->departs_local, 0, 5), 'arrives_local' => substr($leg->arrives_local, 0, 5)])->all();

        return $flight->aircraft_type_id === $payload['aircraft_type_id'] && $flight->active === $payload['active'] && $flight->days->pluck('weekday')->all() === $payload['weekdays'] && $legs === $payload['legs'];
    }
}
