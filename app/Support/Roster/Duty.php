<?php

namespace App\Support\Roster;

use Carbon\CarbonImmutable;

/**
 * One block of working time on a crew member's schedule: a rostered trip (one or more duty periods, e.g. a
 * night stop has two) or a timed activity such as a simulator session.
 *
 * Instants are whole minutes since the Unix epoch (UTC) so comparisons are cheap integer maths. Dates are
 * base-local calendar dates (Y-m-d) worked out when the duty was created, never from a server or browser
 * timezone.
 */
final readonly class Duty
{
    /**
     * Each period has start/end in epoch minutes, its block minutes, the base-local report date and every
     * base-local date it touches.
     *
     * @param  string  $key  unique within a schedule: "assignment:12", "trip:5" (a candidate seat) or "activity:3"
     * @param  string  $label  used in rule messages, e.g. "LB1 on 05 Oct"
     * @param  int|null  $tripId  the rostered trip, or null for an activity
     * @param  array<int, array{start: int, end: int, block: int, date: string, dates: array<int, string>}>  $periods
     */
    public function __construct(public string $key, public string $label, public ?int $tripId, public array $periods) {}

    /**
     * Build a trip duty from a trip's schedule_snapshot (see RosterBuilderService::snapshot()). Returns null
     * for a snapshot without duty periods, which has no times to check.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(string $key, int $tripId, array $snapshot): ?self
    {
        if (empty($snapshot['duties'])) {
            return null;
        }
        $periods = array_map(fn (array $duty): array => [
            'start' => self::minutes($duty['report']),
            'end' => self::minutes($duty['release']),
            'block' => (int) $duty['block_minutes'],
            'date' => $duty['date'],
            'dates' => $duty['dates'],
        ], $snapshot['duties']);

        return new self($key, ($snapshot['code'] ?? 'Trip').' on '.CarbonImmutable::parse($periods[0]['date'])->format('d M'), $tripId, $periods);
    }

    /**
     * The same duty under another key, e.g. a candidate trip duty once it becomes an assignment.
     */
    public function withKey(string $key): self
    {
        return new self($key, $this->label, $this->tripId, $this->periods);
    }

    /** Report time of the first duty period. */
    public function start(): int
    {
        return $this->periods[0]['start'];
    }

    /** Release time of the last duty period (a night stop keeps the crew away until then). */
    public function end(): int
    {
        return $this->periods[array_key_last($this->periods)]['end'];
    }

    /** Duty time: report to release of each period, excluding rest at a night stop. */
    public function dutyMinutes(): int
    {
        return array_sum(array_map(fn (array $period): int => $period['end'] - $period['start'], $this->periods));
    }

    /** Block (flying) time of every period. */
    public function blockMinutes(): int
    {
        return array_sum(array_column($this->periods, 'block'));
    }

    /** First base-local date of the duty, used to place it in a week and month. */
    public function firstDate(): string
    {
        return $this->periods[0]['date'];
    }

    /**
     * Every base-local date the duty touches.
     *
     * @return array<int, string>
     */
    public function dates(): array
    {
        return array_values(array_unique(array_merge(...array_column($this->periods, 'dates'))));
    }

    /** ISO 8601 instant to whole epoch minutes. */
    public static function minutes(string $instant): int
    {
        return intdiv(CarbonImmutable::parse($instant)->getTimestamp(), 60);
    }

    /** 605 -> "10h05", for rule messages. */
    public static function hours(int|float $minutes): string
    {
        $minutes = (int) round($minutes);

        return intdiv($minutes, 60).'h'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** The Monday (Y-m-d) of the base-local week containing a date. */
    public static function weekOf(string $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d');
    }
}
