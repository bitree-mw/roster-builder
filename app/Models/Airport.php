<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An airport keyed by its IATA code. Bases (is_base) are where duties must start and end.
 */
#[Fillable(['code', 'name', 'utc_offset_minutes', 'is_base'])]
class Airport extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['utc_offset_minutes' => 'integer', 'is_base' => 'boolean'];
    }

    /**
     * Crew members based here (only possible while the airport is a base).
     *
     * @return HasMany<CrewMember, $this>
     */
    public function crewMembers(): HasMany
    {
        return $this->hasMany(CrewMember::class, 'base_airport', 'code');
    }

    /**
     * Flight legs departing from this airport.
     *
     * @return HasMany<FlightLeg, $this>
     */
    public function departingLegs(): HasMany
    {
        return $this->hasMany(FlightLeg::class, 'from_airport', 'code');
    }

    /**
     * Flight legs arriving at this airport.
     *
     * @return HasMany<FlightLeg, $this>
     */
    public function arrivingLegs(): HasMany
    {
        return $this->hasMany(FlightLeg::class, 'to_airport', 'code');
    }
}
