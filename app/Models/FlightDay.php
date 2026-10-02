<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One operating weekday of a flight pattern (composite key flight_id + weekday, Monday = 0).
 */
#[Fillable(['flight_id', 'weekday'])]
class FlightDay extends Model
{
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
