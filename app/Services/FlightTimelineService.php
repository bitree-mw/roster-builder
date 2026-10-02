<?php

namespace App\Services;

use App\Models\Airport;
use App\Models\RuleSet;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Pattern times are minutes from midnight on trip day one, in base local time. */
class FlightTimelineService
{
    /**
     * Check a pattern's legs for connectivity, base return, day boundaries and the configured duty/rest limits,
     * and compute each trip day's duty period.
     *
     * @param  array<int, array{trip_day: int, from_airport: string, to_airport: string, departs_local: string, arrives_local: string}>  $legs
     * @return array{base: string, periods: array<int, array<string, mixed>>, warnings: array<int, string>}
     *
     * @throws ValidationException on the first rule the pattern breaks
     */
    public function validate(array $legs, RuleSet $rules): array
    {
        $periods = [];
        $warnings = [];
        $previous = null;
        $previousDay = 0;
        $base = $legs[0]['from_airport'];
        if (! Airport::whereKey($base)->where('is_base', true)->exists()) {
            $this->fail('The first leg must depart from a crew base.');
        }
        foreach ($legs as $index => $leg) {
            $day = (int) $leg['trip_day'];
            if (($index === 0 && $day !== 1) || $day < $previousDay || $day > $previousDay + 1) {
                $this->fail('Trip days must start at 1, stay in order, and have no gaps.');
            }
            if ($leg['from_airport'] === $leg['to_airport']) {
                $this->fail('A leg must arrive at a different airport.');
            }
            if ($previous && $previous['to'] !== $leg['from_airport']) {
                $this->fail('Legs must connect at the same airport.');
            }
            // Minutes from midnight of trip day 1, so later trip days and overnight legs stay comparable.
            $departure = ($day - 1) * 1440 + $this->minutes($leg['departs_local']);
            // A same-day leg whose clock time is "earlier" than the last arrival must be after midnight.
            if ($previous && $day === $previousDay) {
                while ($departure < $previous['arrival']) {
                    $departure += 1440;
                }
            }
            // Arrival on the departure's calendar day, rolled forward when it would precede the departure.
            $arrival = intdiv($departure, 1440) * 1440 + $this->minutes($leg['arrives_local']);
            if ($arrival <= $departure) {
                $arrival += 1440;
            }
            if ($previous && $day !== $previousDay && $departure <= $previous['arrival']) {
                $this->fail('The next trip day must start after the previous arrival.');
            }
            if ($previous && $day === $previousDay && $departure - $previous['arrival'] < 20) {
                $warnings[] = 'Turnaround before leg '.($index + 1).' is under 20 minutes.';
            }
            // The first leg of a trip day opens a duty period: report time, and rest since the previous day's release.
            if (! isset($periods[$day])) {
                $report = $departure - $rules->report_before_min;
                if ($previous && $report - $periods[$previousDay]['release_min'] < $rules->min_rest_h * 60) {
                    $this->fail('The night stop does not provide the required minimum rest.');
                }
                $periods[$day] = ['trip_day' => $day, 'report_min' => $report, 'release_min' => 0, 'block_min' => 0, 'legs' => []];
            }
            // Each leg extends the duty to its arrival plus the release allowance, and adds its block time.
            $periods[$day]['release_min'] = $arrival + $rules->release_after_min;
            $periods[$day]['block_min'] += $arrival - $departure;
            $periods[$day]['legs'][] = [...$leg, 'departure_min' => $departure, 'arrival_min' => $arrival];
            if ($periods[$day]['release_min'] - $periods[$day]['report_min'] > $rules->max_duty_day_h * 60) {
                $this->fail('A duty exceeds the configured maximum daily duty.');
            }
            $previous = ['to' => $leg['to_airport'], 'arrival' => $arrival];
            $previousDay = $day;
        }
        if ($previous['to'] !== $base) {
            $this->fail('The trip must finish back at its starting base.');
        }

        return ['base' => $base, 'periods' => array_values($periods), 'warnings' => $warnings];
    }

    /**
     * Convert base-local minutes on a trip into a UTC ISO 8601 instant using an explicit offset (never the
     * server or browser timezone).
     */
    public function instant(string $startDate, int $localMinutes, int $offsetMinutes): string
    {
        return CarbonImmutable::parse($startDate, 'UTC')->startOfDay()->addMinutes($localMinutes - $offsetMinutes)->toIso8601String();
    }

    /**
     * "HH:MM" (or "HH:MM:SS") to minutes after midnight.
     */
    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * Abort validation with a message attached to the legs field.
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['legs' => $message]);
    }
}
