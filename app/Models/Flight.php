<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring flight pattern (e.g. LB1): operating weekdays plus ordered legs across up to four trip days.
 * Inactive (disabled) patterns stay on file but must not be planned.
 */
#[Fillable(['code', 'aircraft_type_id', 'active'])]
class Flight extends Model
{
    use HasFactory;

    /**
     * Operating weekdays, Monday = 0.
     */
    public function days(): HasMany
    {
        return $this->hasMany(FlightDay::class)->orderBy('weekday');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * The planned aircraft type, used for trips that have no listed airframe (a trip follows its plane's type).
     */
    public function aircraftType(): BelongsTo
    {
        return $this->belongsTo(AircraftType::class);
    }

    /**
     * Airframes that may fly this plan, in order of preference (they may be of different types). The roster
     * build gives each dated trip the first one that is available and not already flying at that time.
     */
    public function aircraft(): BelongsToMany
    {
        return $this->belongsToMany(Aircraft::class, 'flight_aircraft')->withPivot('position')->orderByPivot('position');
    }

    /**
     * Legs in flying order: by trip day, then sequence within the day.
     */
    public function legs(): HasMany
    {
        return $this->hasMany(FlightLeg::class)->orderBy('trip_day')->orderBy('sequence');
    }

    /**
     * Dated trips generated from this pattern in roster periods (their existence blocks deletion).
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
