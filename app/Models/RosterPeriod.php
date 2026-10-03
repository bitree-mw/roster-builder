<?php

namespace App\Models;

use App\Casts\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One roster period in base-local dates, draft or published: a week (Monday to Sunday), a fortnight (two
 * weeks from a Monday) or a calendar month. Periods never overlap. rules_snapshot freezes the duty rules in
 * force when the period was created so later rule changes never reinterpret it. Status, build and
 * publication fields are set by RosterPeriodService and RosterBuilderService only, never mass-assigned.
 */
#[Fillable(['starts_on', 'ends_on', 'length', 'rules_snapshot'])]
class RosterPeriod extends Model
{
    use HasFactory;

    /** Period lengths and their labels. */
    public const LENGTHS = ['week' => '1 week', 'fortnight' => '2 weeks', 'month' => 'Month'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_on' => LocalDate::class, 'ends_on' => LocalDate::class, 'rules_snapshot' => 'array', 'built_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    /**
     * Dated trips planned in this week.
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * The user who last ran the roster generator for this week.
     */
    public function builder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'built_by');
    }

    /**
     * The user who published the week to crew.
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Human label used in messages and headings: "week 41 (05–11 Oct 2026)", "weeks 41–42 (05–18 Oct 2026)"
     * or "October 2026".
     */
    public function label(): string
    {
        if ($this->length === 'month') {
            return $this->starts_on->format('F Y');
        }
        $sameMonth = $this->starts_on->month === $this->ends_on->month;
        $range = $this->starts_on->format($sameMonth ? 'd' : 'd M').'–'.$this->ends_on->format('d M Y');

        return $this->length === 'fortnight'
            ? 'weeks '.$this->starts_on->isoWeek.'–'.$this->ends_on->isoWeek.' ('.$range.')'
            : 'week '.$this->starts_on->isoWeek.' ('.$range.')';
    }

    /**
     * The last day of a period of the given length starting on a date (Monday, or the 1st for a month).
     */
    public static function endFor(CarbonImmutable $start, string $length): CarbonImmutable
    {
        return match ($length) {
            'month' => $start->endOfMonth()->startOfDay(),
            'fortnight' => $start->addDays(13),
            default => $start->addDays(6),
        };
    }

    /**
     * Every base-local date in the period, in order.
     *
     * @return array<int, string>
     */
    public function dates(): array
    {
        $dates = [];
        for ($date = $this->starts_on; $date->lte($this->ends_on); $date = $date->addDay()) {
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }
}
