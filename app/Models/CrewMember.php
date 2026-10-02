<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pilot (CPT/FO) or cabin crew member (CC). Inactive crew stay on file for history but are not rostered;
 * only cabin crew may be rated on all aircraft.
 */
#[Fillable(['name', 'email', 'rank', 'base_airport', 'all_aircraft', 'active'])]
class CrewMember extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['all_aircraft' => 'boolean', 'active' => 'boolean'];
    }

    /**
     * Aircraft types the crew member is rated on.
     */
    public function ratings(): BelongsToMany
    {
        return $this->belongsToMany(AircraftType::class, 'crew_ratings');
    }

    /**
     * Licence, medical and recurrent expiry records.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CrewDocument::class);
    }

    /**
     * Leave, simulator, standby and day-off planning items.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(CrewActivity::class);
    }

    /**
     * Seats this crew member holds on rostered trips.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * Home base airport, keyed by IATA code.
     */
    public function base(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'base_airport', 'code');
    }
}
