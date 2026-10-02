<?php

namespace App\Services;

use App\Models\Airport;
use App\Models\RuleSet;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Pattern times are minutes from midnight on trip day one, in base local time. */
class FlightTimelineService
{
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
            $departure = ($day - 1) * 1440 + $this->minutes($leg['departs_local']);
            if ($previous && $day === $previousDay) {
                while ($departure < $previous['arrival']) {
                    $departure += 1440;
                }
            }
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
            if (! isset($periods[$day])) {
                $report = $departure - $rules->report_before_min;
                if ($previous && $report - $periods[$previousDay]['release_min'] < $rules->min_rest_h * 60) {
                    $this->fail('The night stop does not provide the required minimum rest.');
                }
                $periods[$day] = ['trip_day' => $day, 'report_min' => $report, 'release_min' => 0, 'block_min' => 0, 'legs' => []];
            }
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

    public function instant(string $startDate, int $localMinutes, int $offsetMinutes): string
    {
        return CarbonImmutable::parse($startDate, 'UTC')->startOfDay()->addMinutes($localMinutes - $offsetMinutes)->toIso8601String();
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['legs' => $message]);
    }
}
