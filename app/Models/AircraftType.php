<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fleet type such as Q400 or B737. Defines the cabin crew complement every flight of the type needs
 * and the semantic roster palette (a token name, never a colour value).
 */
#[Fillable(['code', 'cabin_crew_required', 'palette'])]
class AircraftType extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cabin_crew_required' => 'integer'];
    }

    /**
     * Flight patterns operated with this type; deletion is blocked while any exist.
     */
    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    /**
     * Registered airframes (tails) of this type.
     */
    public function aircraft(): HasMany
    {
        return $this->hasMany(Aircraft::class);
    }

    /**
     * Crew rated on this type, through the crew_ratings pivot.
     */
    public function crewMembers(): BelongsToMany
    {
        return $this->belongsToMany(CrewMember::class, 'crew_ratings');
    }
}
