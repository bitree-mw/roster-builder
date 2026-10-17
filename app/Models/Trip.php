<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dated instance of a flight pattern inside a roster period. schedule_snapshot keeps the legs (as UTC
 * instants) and the aircraft exactly as planned, so editing the pattern later does not change history.
 *
 * aircraft_id is the airframe flying the trip: picked by the roster build from the flight plan's list
 * (aircraft_source "auto") or set by crew control ("manual", kept on rebuild). Both are set by services only.
 */
#[Fillable(['roster_period_id', 'flight_id', 'start_date', 'schedule_snapshot'])]
class Trip extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['start_date' => LocalDate::class, 'schedule_snapshot' => 'array'];
    }

    /**
     * The month this trip is rostered in.
     */
    public function rosterPeriod(): BelongsTo
    {
        return $this->belongsTo(RosterPeriod::class);
    }

    /**
     * The pattern the trip was generated from.
     */
    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    /**
     * The airframe flying this trip, if one is assigned.
     */
    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /**
     * Crew seats on the trip.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * Crew who must not be assigned to this trip.
     */
    public function exclusions(): HasMany
    {
        return $this->hasMany(Exclusion::class);
    }
}
