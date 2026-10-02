<?php

namespace App\Support\Roster;

/**
 * Everything already planned for one crew member around a roster week: rostered trips (from this and
 * neighbouring weeks), timed activities that count as duty, and whole days on which they cannot fly.
 */
final class CrewSchedule
{
    /** @var array<string, Duty> keyed by Duty::$key */
    public array $duties = [];

    /** @var array<string, string> base-local date => activity type (leave, day_off, sim, standby) */
    public array $unavailable = [];

    /** Add (or replace) a duty. */
    public function add(Duty $duty): void
    {
        $this->duties[$duty->key] = $duty;
    }

    /** Remove a duty, e.g. when the generator releases an automatic seat before rebuilding. */
    public function remove(string $key): void
    {
        unset($this->duties[$key]);
    }

    /**
     * Duties to check a candidate duty against: everything except the seat being (re)filled and any other
     * seat on the same trip (holding two seats on one trip is reported separately).
     *
     * @return array<int, Duty>
     */
    public function others(?string $exceptKey, ?int $tripId): array
    {
        return array_values(array_filter($this->duties, fn (Duty $duty): bool => $duty->key !== $exceptKey && ($tripId === null || $duty->tripId !== $tripId)));
    }

    /**
     * Duty minutes whose report date falls in the week starting on the given Monday.
     */
    public function weekMinutes(string $monday, ?string $exceptKey = null): int
    {
        $total = 0;
        foreach ($this->duties as $duty) {
            if ($duty->key === $exceptKey) {
                continue;
            }
            foreach ($duty->periods as $period) {
                if (Duty::weekOf($period['date']) === $monday) {
                    $total += $period['end'] - $period['start'];
                }
            }
        }

        return $total;
    }

    /**
     * Block minutes whose report date falls in the week starting on the given Monday.
     */
    public function weekBlockMinutes(string $monday): int
    {
        $total = 0;
        foreach ($this->duties as $duty) {
            foreach ($duty->periods as $period) {
                if (Duty::weekOf($period['date']) === $monday) {
                    $total += $period['block'];
                }
            }
        }

        return $total;
    }
}
