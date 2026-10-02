<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'aircraft_type_id', 'active'])]
class Flight extends Model
{
    use HasFactory;

    public function days(): HasMany
    {
        return $this->hasMany(FlightDay::class)->orderBy('weekday');
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function aircraftType(): BelongsTo
    {
        return $this->belongsTo(AircraftType::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(FlightLeg::class)->orderBy('trip_day')->orderBy('sequence');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
