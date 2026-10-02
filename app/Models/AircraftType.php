<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'cabin_crew_required', 'palette'])]
class AircraftType extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['cabin_crew_required' => 'integer'];
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    public function aircraft(): HasMany
    {
        return $this->hasMany(Aircraft::class);
    }

    public function crewMembers(): BelongsToMany
    {
        return $this->belongsToMany(CrewMember::class, 'crew_ratings');
    }
}
