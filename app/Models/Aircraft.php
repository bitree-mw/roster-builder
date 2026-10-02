<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An individual airframe (tail) of an aircraft type. Status is changed only through AircraftService::changeStatus. */
#[Fillable(['registration', 'aircraft_type_id', 'airframe_hours', 'notes'])]
class Aircraft extends Model
{
    use HasFactory;

    /** @var array<string, string> */
    public const STATUSES = [
        'available' => 'Available',
        'maintenance' => 'In maintenance',
        'grounded' => 'Grounded (AOG)',
        'unavailable' => 'Unavailable',
    ];

    protected $table = 'aircraft';

    protected function casts(): array
    {
        return ['airframe_hours' => 'float', 'status_changed_at' => 'immutable_datetime'];
    }

    public function aircraftType(): BelongsTo
    {
        return $this->belongsTo(AircraftType::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }
}
