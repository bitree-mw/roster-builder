<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * The aircraft type that operates the pattern.
     */
    public function aircraftType(): BelongsTo
    {
        return $this->belongsTo(AircraftType::class);
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
