<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CrewMember;
use App\Models\RosterPeriod;
use App\Models\Trip;
use App\Support\Csv;
use Carbon\CarbonImmutable;

/**
 * Roster exports for one week: a CSV of seats (all seats for staff, own seats for crew), PDF files (the full
 * grid or one page per crew member) and an iCalendar (.ics) file of one crew member's duties and
 * activities. All are built from the same planning context as the roster window, so they always match
 * what is on screen.
 *
 * CSV cells that a spreadsheet would treat as a formula are prefixed with an apostrophe. Calendar events
 * use UTC instants (the calendar app shows them in local time); descriptions repeat base-local times.
 */
class RosterExportService
{
    private const ACTIVITIES = ['leave' => 'Leave', 'day_off' => 'Day off', 'sim' => 'Simulator', 'standby' => 'Standby'];

    private const RANK_LABELS = ['CPT' => 'Captain', 'FO' => 'First officer', 'CC' => 'Cabin crew'];

    public function __construct(private RosterLegalityService $legality, private PdfService $pdf) {}

    /**
     * Roster PDF for a week. "grid" is the whole crew × day roster on landscape pages; "crew" is one page per
     * crew member (all crew with duties or plans this week, or only $crewIds when given — individual or
     * selected sets).
     *
     * @param  'grid'|'crew'  $layout
     * @param  array<int, int>|null  $crewIds
     */
    public function pdf(RosterPeriod $period, string $layout, ?array $crewIds = null, ?string $requestedBy = null): string
    {
        $context = $this->legality->context($period);
        $monday = $period->starts_on->format('Y-m-d');
        $days = [];
        for ($index = 0; $index < 7; $index++) {
            $date = $period->starts_on->addDays($index);
            $days[] = ['date' => $date->format('Y-m-d'), 'label' => $date->format('D d M')];
        }
        $offset = (int) $context->rules['utc_offset_minutes'];
        $local = fn ($instant): string => $instant ? CarbonImmutable::parse($instant)->utc()->addMinutes($offset)->format('H:i') : '';
        $people = [];
        foreach ($context->crews as $crew) {
            $person = ['id' => $crew->id, 'name' => $crew->name, 'rank' => $crew->rank, 'rank_label' => self::RANK_LABELS[$crew->rank], 'base' => $crew->base_airport, 'cells' => [], 'duties' => [], 'activities' => [], 'block' => 0, 'duty' => 0];
            foreach ($context->trips as $trip) {
                $seat = $trip->assignments->firstWhere('crew_member_id', $crew->id);
                if ($seat === null) {
                    continue;
                }
                $snapshot = $trip->schedule_snapshot;
                $seatLabel = $seat->rank === 'CC' ? 'CC'.$seat->seat_number : $seat->rank;
                foreach ($snapshot['duties'] ?? [] as $duty) {
                    foreach ($duty['dates'] as $date) {
                        $person['cells'][$date][] = ['kind' => 'duty', 'code' => $snapshot['code'] ?? '', 'seat' => $seatLabel, 'times' => $date === $duty['date'] ? $duty['report_local'].'–'.$duty['release_local'] : 'until '.$duty['release_local']];
                    }
                    $person['duties'][] = ['date' => CarbonImmutable::parse($duty['date'])->format('D d M'), 'code' => $snapshot['code'] ?? '', 'aircraft' => $snapshot['aircraft_type'] ?? '', 'seat' => $seatLabel, 'route' => $snapshot['route'] ?? '',
                        'report_local' => $duty['report_local'], 'release_local' => $duty['release_local'], 'utc' => $this->utc($duty['report']).' – '.substr($this->utc($duty['release']), 11).' UTC',
                        'block' => $this->hours($duty['block_minutes']), 'duty' => $this->hours($duty['duty_minutes'])];
                    $person['block'] += $duty['block_minutes'];
                    $person['duty'] += $duty['duty_minutes'];
                }
            }
            foreach ($crew->activities as $activity) {
                $date = $activity->date->format('Y-m-d');
                if ($date < $monday || $date > $period->ends_on->format('Y-m-d')) {
                    continue;
                }
                $times = $activity->starts_at && $activity->ends_at ? $local($activity->starts_at).'–'.$local($activity->ends_at) : '';
                $label = self::ACTIVITIES[$activity->type];
                $person['cells'][$date][] = ['kind' => 'activity', 'label' => $label, 'times' => $times];
                $person['activities'][] = ['date' => $activity->date->format('D d M'), 'label' => $label, 'times' => $times, 'note' => $activity->note];
            }
            $person['week'] = $this->hours($context->schedule($crew->id)->weekMinutes($monday));
            $person['summary'] = ['Duties' => count($person['duties']), 'Block' => $this->hours($person['block']), 'Duty' => $this->hours($person['duty']), 'Week incl. SIM/standby' => $person['week']];
            $people[] = $person;
        }
        $subtitle = ucfirst($period->label()).' · '.($period->status === 'published' ? 'Published' : 'Draft — not released to crew');
        $base = ['subtitle' => $subtitle, 'generatedBy' => $requestedBy];

        if ($layout === 'crew') {
            $selected = array_values(array_filter($people, fn (array $person): bool => $crewIds !== null ? in_array($person['id'], $crewIds, true) : ($person['duties'] !== [] || $person['activities'] !== [])));

            return $this->pdf->render('pdf.roster-crew', [...$base, 'title' => 'Crew roster', 'people' => $selected], 'portrait');
        }
        $open = [];
        $seats = 0;
        $filled = 0;
        foreach ($context->trips as $trip) {
            foreach ($trip->assignments as $seat) {
                $seats++;
                if ($seat->crew_member_id === null) {
                    $open[$trip->start_date->format('Y-m-d')][] = ['code' => $trip->schedule_snapshot['code'] ?? '', 'seat' => $seat->rank === 'CC' ? 'CC'.$seat->seat_number : $seat->rank];
                } else {
                    $filled++;
                }
            }
        }
        $rows = array_values(array_filter($people, fn (array $person): bool => $person['cells'] !== []));
        usort($rows, fn (array $a, array $b): int => [array_search($a['rank'], ['CPT', 'FO', 'CC'], true), $a['name']] <=> [array_search($b['rank'], ['CPT', 'FO', 'CC'], true), $b['name']]);

        return $this->pdf->render('pdf.roster-grid', [...$base, 'title' => 'Weekly crew roster', 'days' => $days, 'rows' => $rows, 'open' => $open,
            'summary' => ['Trips' => $context->trips->count(), 'Seats filled' => $filled.' / '.$seats, 'Open seats' => $seats - $filled, 'Crew rostered' => count(array_filter($rows, fn (array $row): bool => $row['duties'] !== []))]]);
    }

    /**
     * CSV of the week's seats, one row per duty period and seat, in trip order. With $crewId only that crew
     * member's seats are included.
     */
    public function csv(RosterPeriod $period, ?int $crewId = null): string
    {
        $context = $this->legality->context($period);
        $rows = [];
        foreach ($context->trips as $trip) {
            $snapshot = $trip->schedule_snapshot;
            foreach ($trip->assignments->sortBy(fn (Assignment $seat): array => [array_search($seat->rank, ['CPT', 'FO', 'CC'], true), $seat->seat_number]) as $seat) {
                if ($crewId !== null && $seat->crew_member_id !== $crewId) {
                    continue;
                }
                $crew = $seat->crew_member_id ? $context->crews->get($seat->crew_member_id) : null;
                foreach ($snapshot['duties'] ?? [] as $duty) {
                    $rows[] = [
                        $duty['date'], $snapshot['code'] ?? '', $snapshot['aircraft_type'] ?? '', $snapshot['route'] ?? '', $duty['trip_day'],
                        $duty['report_local'], $duty['release_local'], $this->utc($duty['report']), $this->utc($duty['release']),
                        $this->hours($duty['block_minutes']), $this->hours($duty['duty_minutes']),
                        $seat->rank === 'CC' ? 'CC'.$seat->seat_number : $seat->rank, $crew?->name ?? 'OPEN', $crew?->base_airport ?? '', $seat->source,
                    ];
                }
            }
        }

        return Csv::write(['Date', 'Flight', 'Aircraft', 'Route', 'Trip day', 'Report (LT)', 'Release (LT)', 'Report (UTC)', 'Release (UTC)', 'Block', 'Duty', 'Seat', 'Crew member', 'Base', 'Source'], $rows);
    }

    /**
     * iCalendar file with one event per duty period of the crew member's seats, plus their activities in the
     * week (leave and days off as all-day events, timed SIM/standby as timed events).
     */
    public function calendar(RosterPeriod $period, CrewMember $crew): string
    {
        $context = $this->legality->context($period);
        $stamp = CarbonImmutable::now('UTC')->format('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Malawi Airlines//Roster Builder//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->text('Roster '.$crew->name.' – '.ucfirst($period->label()))];
        foreach ($context->trips as $trip) {
            $seat = $trip->assignments->firstWhere('crew_member_id', $crew->id);
            if ($seat === null) {
                continue;
            }
            foreach ($trip->schedule_snapshot['duties'] ?? [] as $duty) {
                $lines = [...$lines, ...$this->event(
                    'trip-'.$trip->id.'-day'.$duty['trip_day'].'-crew'.$crew->id,
                    $stamp,
                    ['DTSTART:'.$this->icsInstant($duty['report']), 'DTEND:'.$this->icsInstant($duty['release'])],
                    ($trip->schedule_snapshot['code'] ?? 'Trip').' '.($seat->rank === 'CC' ? 'CC'.$seat->seat_number : $seat->rank).' '.($trip->schedule_snapshot['route'] ?? ''),
                    $this->description($trip, $duty),
                    $trip->schedule_snapshot['base'] ?? '',
                )];
            }
        }
        $monday = $period->starts_on->format('Y-m-d');
        $sunday = $period->ends_on->format('Y-m-d');
        foreach ($context->crews->get($crew->id)?->activities ?? [] as $activity) {
            $date = $activity->date->format('Y-m-d');
            if ($date < $monday || $date > $sunday) {
                continue;
            }
            $when = $activity->starts_at && $activity->ends_at
                ? ['DTSTART:'.$activity->starts_at->utc()->format('Ymd\THis\Z'), 'DTEND:'.$activity->ends_at->utc()->format('Ymd\THis\Z')]
                : ['DTSTART;VALUE=DATE:'.$activity->date->format('Ymd'), 'DTEND;VALUE=DATE:'.$activity->date->addDay()->format('Ymd')];
            $lines = [...$lines, ...$this->event('activity-'.$activity->id, $stamp, $when, self::ACTIVITIES[$activity->type], (string) $activity->note, $crew->base_airport)];
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([$this, 'fold'], $lines))."\r\n";
    }

    /**
     * One VEVENT.
     *
     * @param  array<int, string>  $when  DTSTART/DTEND lines
     * @return array<int, string>
     */
    private function event(string $uid, string $stamp, array $when, string $summary, string $description, string $location): array
    {
        return ['BEGIN:VEVENT', 'UID:'.$uid.'@roster-builder', 'DTSTAMP:'.$stamp, ...$when, 'SUMMARY:'.$this->text($summary),
            'DESCRIPTION:'.$this->text($description), 'LOCATION:'.$this->text($location), 'END:VEVENT'];
    }

    /** Sector list with base-local times for an event description. */
    private function description(Trip $trip, array $duty): string
    {
        $legs = array_map(fn (array $leg): string => $leg['from_airport'].'-'.$leg['to_airport'].' '.$leg['departs_local'].'-'.$leg['arrives_local'].' LT', $duty['legs']);

        return 'Report '.$duty['report_local'].' LT, release '.$duty['release_local'].' LT. '.implode('; ', $legs).'. Aircraft '.($trip->schedule_snapshot['aircraft_type'] ?? '').'.';
    }

    /** Escape text for iCalendar: backslash, semicolon, comma and newlines. */
    private function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $value);
    }

    /** Fold lines longer than 75 octets (RFC 5545), without splitting a multibyte character. */
    private function fold(string $line): string
    {
        $out = '';
        $length = 0;
        foreach (mb_str_split($line) as $character) {
            $bytes = strlen($character);
            if ($length + $bytes > 75) {
                $out .= "\r\n ";
                $length = 1;
            }
            $out .= $character;
            $length += $bytes;
        }

        return $out;
    }

    /** ISO 8601 instant to iCalendar UTC form, e.g. 20261005T050000Z. */
    private function icsInstant(string $instant): string
    {
        return CarbonImmutable::parse($instant)->utc()->format('Ymd\THis\Z');
    }

    /** ISO 8601 instant to "2026-10-05 05:00" UTC for the CSV. */
    private function utc(string $instant): string
    {
        return CarbonImmutable::parse($instant)->utc()->format('Y-m-d H:i');
    }

    /** Minutes to "4:10". */
    private function hours(int $minutes): string
    {
        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
