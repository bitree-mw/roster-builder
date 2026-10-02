<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'email', 'rank', 'base_airport', 'all_aircraft', 'active'])]
class CrewMember extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['all_aircraft' => 'boolean', 'active' => 'boolean'];
    }

    public function ratings(): BelongsToMany
    {
        return $this->belongsToMany(AircraftType::class, 'crew_ratings');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CrewDocument::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrewActivity::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function base(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'base_airport', 'code');
    }
}
