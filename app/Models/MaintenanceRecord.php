<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A completed check or service. The latest record of each kind per aircraft sets the next due date/hours. */
#[Fillable(['aircraft_id', 'kind', 'title', 'performed_on', 'airframe_hours_at', 'next_due_on', 'next_due_hours', 'notes'])]
class MaintenanceRecord extends Model
{
    use HasFactory;

    /** @var array<string, string> */
    public const KINDS = [
        'line_check' => 'Line check',
        'a_check' => 'A-check',
        'c_check' => 'C-check',
        'inspection' => 'Scheduled inspection',
        'component' => 'Component / hard-time item',
        'service' => 'Servicing',
        'other' => 'Other',
    ];

    /**
     * Calendar dates use LocalDate (no time component); hours are fractional.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performed_on' => LocalDate::class,
            'next_due_on' => LocalDate::class,
            'airframe_hours_at' => 'float',
            'next_due_hours' => 'float',
        ];
    }

    /**
     * The airframe the work was done on.
     */
    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    /**
     * The user who recorded the work (set by MaintenanceService, never from the request).
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
