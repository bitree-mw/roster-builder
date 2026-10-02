<?php

namespace App\Services;

use App\Models\RuleSet;
use Carbon\CarbonImmutable;

/** Calendar-date expiry decisions, evaluated against today's base-local date (never the browser's timezone). */
class ExpiryService
{
    /**
     * The base's UTC offset, memoised for the request (the service is bound as scoped in AppServiceProvider).
     * Only the offset is cached, so "today" still moves on in long-running processes.
     */
    private ?int $offset = null;

    /**
     * Today's calendar date at base, using the standard rule set's UTC offset (default +2:00, Malawi).
     */
    public function today(): CarbonImmutable
    {
        $this->offset ??= (int) (RuleSet::query()->whereKey(1)->value('utc_offset_minutes') ?? 120);

        return CarbonImmutable::parse(CarbonImmutable::now('UTC')->addMinutes($this->offset)->format('Y-m-d'), 'UTC');
    }

    /**
     * Whole days from today to the date; negative when the date has passed.
     */
    public function daysUntil(CarbonImmutable $date): int
    {
        return (int) $this->today()->diffInDays($date, false);
    }

    /**
     * Classify a date: expired once it has passed, due_soon within the warning window, otherwise valid.
     * A document expiring today is still valid today.
     *
     * @return 'expired'|'due_soon'|'valid'
     */
    public function dateState(CarbonImmutable $date, int $warningDays): string
    {
        $days = $this->daysUntil($date);

        return $days < 0 ? 'expired' : ($days <= $warningDays ? 'due_soon' : 'valid');
    }

    /**
     * Crew document state using the configured warning window (config/roster.php).
     *
     * @return 'expired'|'due_soon'|'valid'
     */
    public function documentState(CarbonImmutable $expiresOn): string
    {
        return $this->dateState($expiresOn, (int) config('roster.document_warning_days'));
    }
}
