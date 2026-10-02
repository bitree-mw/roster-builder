<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned non-flying day item for a crew member: leave, simulator, standby or a protected day off.
 * Timed activities (starts_at/ends_at, UTC) count as duty. roster_period_id is set only on standby the roster
 * generator planned to fill a workload gap (a rebuild replaces it); it is never mass-assigned.
 */
#[Fillable(['crew_member_id', 'date', 'type', 'starts_at', 'ends_at', 'note'])]
class CrewActivity extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => LocalDate::class, 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /**
     * The crew member the activity is planned for.
     */
    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }

    /**
     * The roster week whose build planned this standby (null for activities entered by people).
     */
    public function rosterPeriod(): BelongsTo
    {
        return $this->belongsTo(RosterPeriod::class);
    }
}
