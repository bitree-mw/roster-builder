<?php

namespace App\Support\Roster;

use Carbon\CarbonImmutable;

/**
 * Part of a trip, as a range of its legs. Captains may fly only a section of a flight pattern (for example
 * LLW-BLZ-CPT-LLW of LLW-BLZ-CPT-LLW-NBO-LLW, getting off at LLW before the NBO legs), while first officers
 * and cabin crew always fly the whole trip.
 *
 * Legs are numbered from 1 across the whole trip, in the order of the schedule snapshot (duty period, then
 * leg). A null range means the whole trip. restrict() turns a trip's schedule snapshot into the snapshot of
 * one section with each duty period's report and release worked out again from the section's own legs, so
 * every duty check, hour total, export and email treats a captain's section as their real duty.
 */
final class Section
{
    /**
     * The trip's legs in order, each with its 1-based number and the index of its duty period.
     *
     * @param  array<string, mixed>  $snapshot  a trip's schedule_snapshot
     * @return array<int, array<string, mixed>> legs with 'number' and 'duty' added
     */
    public static function legs(array $snapshot): array
    {
        $legs = [];
        foreach ($snapshot['duties'] ?? [] as $dutyIndex => $duty) {
            foreach ($duty['legs'] ?? [] as $leg) {
                $legs[] = [...$leg, 'number' => count($legs) + 1, 'duty' => $dutyIndex];
            }
        }

        return $legs;
    }

    /** How many legs the trip has. */
    public static function count(array $snapshot): int
    {
        return count(self::legs($snapshot));
    }

    /**
     * Whether a range is only part of the trip (a null range, or one covering every leg, is the whole trip).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function isPartial(array $snapshot, ?int $from, ?int $to): bool
    {
        if ($from === null || $to === null) {
            return false;
        }

        return $from > 1 || $to < self::count($snapshot);
    }

    /**
     * Whether a range can be used on this trip: both ends set, in order and within the trip's legs.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function valid(array $snapshot, ?int $from, ?int $to): bool
    {
        return $from === null && $to === null
            || ($from !== null && $to !== null && $from >= 1 && $from <= $to && $to <= self::count($snapshot));
    }

    /**
     * The airports where a range starts and ends, e.g. ['LLW', 'LLW'] for the first loop of the example above.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{0: ?string, 1: ?string}
     */
    public static function ends(array $snapshot, ?int $from, ?int $to): array
    {
        $legs = self::slice($snapshot, $from, $to);
        if ($legs === []) {
            return [null, null];
        }

        return [$legs[0]['from_airport'], $legs[array_key_last($legs)]['to_airport']];
    }

    /**
     * The airports a range visits, e.g. "LLW-BLZ-CPT-LLW".
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function route(array $snapshot, ?int $from, ?int $to): string
    {
        $legs = self::slice($snapshot, $from, $to);
        if ($legs === []) {
            return (string) ($snapshot['route'] ?? '');
        }

        return implode('-', [$legs[0]['from_airport'], ...array_column($legs, 'to_airport')]);
    }

    /**
     * The trip's legs as airport pairs ("LLW>BLZ,BLZ>CPT,…"). When a flight pattern changes so that this
     * differs, captain sections no longer line up with the legs and are reset to one captain for the whole trip.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function signature(array $snapshot): string
    {
        return implode(',', array_map(fn (array $leg): string => $leg['from_airport'].'>'.$leg['to_airport'], self::legs($snapshot)));
    }

    /**
     * The schedule snapshot of one section: only its legs, each duty period's report and release recalculated
     * from its first and last leg (keeping the trip's own report-before and release-after margins), local
     * clock times and dates recomputed with the snapshot's explicit UTC offset, and totals summed again. The
     * whole trip is returned unchanged. The result carries 'section' (legs, airports) for display.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function restrict(array $snapshot, ?int $from, ?int $to): array
    {
        if (! self::isPartial($snapshot, $from, $to)) {
            return $snapshot;
        }
        $offset = (int) ($snapshot['utc_offset_minutes'] ?? 0);
        $duties = [];
        $number = 0;
        foreach ($snapshot['duties'] ?? [] as $duty) {
            $kept = [];
            foreach ($duty['legs'] ?? [] as $leg) {
                $number++;
                if ($number >= $from && $number <= $to) {
                    $kept[] = $leg;
                }
            }
            if ($kept === []) {
                continue;
            }
            // The trip's own margins: report before the first departure, release after the last arrival.
            $reportBefore = Duty::minutes($duty['legs'][0]['departs']) - Duty::minutes($duty['report']);
            $releaseAfter = Duty::minutes($duty['release']) - Duty::minutes($duty['legs'][array_key_last($duty['legs'])]['arrives']);
            $report = Duty::minutes($kept[0]['departs']) - $reportBefore;
            $release = Duty::minutes($kept[array_key_last($kept)]['arrives']) + $releaseAfter;
            $dates = [];
            for ($day = self::localDate($report, $offset); $day <= self::localDate($release - 1, $offset); $day = gmdate('Y-m-d', strtotime($day.' 00:00:00 UTC') + 86400)) {
                $dates[] = $day;
            }
            $duties[] = [
                ...$duty,
                'date' => self::localDate($report, $offset),
                'dates' => $dates,
                'report' => self::instant($report), 'release' => self::instant($release),
                'report_local' => self::localClock($report, $offset), 'release_local' => self::localClock($release, $offset),
                'block_minutes' => array_sum(array_column($kept, 'block_minutes')),
                'duty_minutes' => $release - $report,
                'legs' => $kept,
            ];
        }
        if ($duties === []) {
            return $snapshot;
        }
        [$start, $end] = self::ends($snapshot, $from, $to);

        return [
            ...$snapshot,
            'route' => self::route($snapshot, $from, $to),
            'report' => $duties[0]['report'],
            'release' => $duties[array_key_last($duties)]['release'],
            'block_minutes' => array_sum(array_column($duties, 'block_minutes')),
            'duty_minutes' => array_sum(array_column($duties, 'duty_minutes')),
            'duties' => $duties,
            'section' => ['from_leg' => $from, 'to_leg' => $to, 'legs' => self::count($snapshot), 'from_airport' => $start, 'to_airport' => $end, 'trip_route' => $snapshot['route'] ?? null],
        ];
    }

    /**
     * The legs inside a range (every leg for a null range).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private static function slice(array $snapshot, ?int $from, ?int $to): array
    {
        $legs = self::legs($snapshot);
        if ($from === null || $to === null) {
            return $legs;
        }

        return array_values(array_filter($legs, fn (array $leg): bool => $leg['number'] >= $from && $leg['number'] <= $to));
    }

    /** Epoch minutes as the snapshot's UTC ISO 8601 format ("2026-10-21T05:00:00+00:00"). */
    private static function instant(int $minutes): string
    {
        return CarbonImmutable::createFromTimestampUTC($minutes * 60)->toIso8601String();
    }

    /** Base-local calendar date of an instant, using the explicit offset (never a server or browser timezone). */
    private static function localDate(int $minutes, int $offset): string
    {
        return gmdate('Y-m-d', ($minutes + $offset) * 60);
    }

    /** Base-local "HH:MM" of an instant, using the explicit offset. */
    private static function localClock(int $minutes, int $offset): string
    {
        return gmdate('H:i', ($minutes + $offset) * 60);
    }
}
