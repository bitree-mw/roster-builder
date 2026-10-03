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
        'maintenance' => 'Not available (maintenance)',
        'grounded' => 'Not available (AOG)',
        'unavailable' => 'Not available',
    ];

    /** "aircraft" is its own plural, so the table name is set explicitly. */
    protected $table = 'aircraft';

    /**
     * Hours are fractional (one decimal place); status changes are recorded as immutable instants.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['airframe_hours' => 'float', 'status_changed_at' => 'immutable_datetime'];
    }

    /**
     * The type (e.g. Q400) whose flights this airframe can operate.
     */
    public function aircraftType(): BelongsTo
    {
        return $this->belongsTo(AircraftType::class);
    }

    /**
     * Every check or service recorded for this airframe; the newest of each kind drives due alerts.
     */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }
}
