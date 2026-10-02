<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['flight_id', 'trip_day', 'sequence', 'from_airport', 'to_airport', 'departs_local', 'arrives_local'])]
class FlightLeg extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['trip_day' => 'integer', 'sequence' => 'integer'];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
