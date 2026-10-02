<?php

namespace App\Services;

use App\Models\RuleSet;
use Carbon\CarbonImmutable;

/** Calendar-date expiry decisions, evaluated against today's base-local date (never the browser's timezone). */
class ExpiryService
{
    private ?CarbonImmutable $today = null;

    public function today(): CarbonImmutable
    {
        if ($this->today === null) {
            $offset = (int) (RuleSet::query()->whereKey(1)->value('utc_offset_minutes') ?? 120);
            $this->today = CarbonImmutable::parse(CarbonImmutable::now('UTC')->addMinutes($offset)->format('Y-m-d'), 'UTC');
        }

        return $this->today;
    }

    public function daysUntil(CarbonImmutable $date): int
    {
        return (int) $this->today()->diffInDays($date, false);
    }

    /** @return 'expired'|'due_soon'|'valid' */
    public function dateState(CarbonImmutable $date, int $warningDays): string
    {
        $days = $this->daysUntil($date);

        return $days < 0 ? 'expired' : ($days <= $warningDays ? 'due_soon' : 'valid');
    }

    /** @return 'expired'|'due_soon'|'valid' */
    public function documentState(CarbonImmutable $expiresOn): string
    {
        return $this->dateState($expiresOn, (int) config('roster.document_warning_days'));
    }
}
