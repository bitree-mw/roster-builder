<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['flight_id', 'weekday'])]
class FlightDay extends Model
{
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
