<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One roster week, Monday (starts_on) to Sunday (ends_on) in base-local dates, either draft or published.
 * rules_snapshot freezes the duty rules in force when the week was created so later rule changes never
 * reinterpret it. Status, build and publication fields are set by RosterPeriodService and
 * RosterBuilderService only, never mass-assigned from a request.
 */
#[Fillable(['starts_on', 'ends_on', 'rules_snapshot'])]
class RosterPeriod extends Model
{
    use HasFactory;

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
     * Human label used in messages and headings, e.g. "week 41 (05–11 Oct 2026)".
     */
    public function label(): string
    {
        $sameMonth = $this->starts_on->month === $this->ends_on->month;

        return 'week '.$this->starts_on->isoWeek.' ('.$this->starts_on->format($sameMonth ? 'd' : 'd M').'–'.$this->ends_on->format('d M Y').')';
    }
}
