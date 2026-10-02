<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat (CPT, FO or a numbered cabin seat) on a rostered trip. A null crew member means open time.
 * source records whether the planner or a person made the assignment; flags and the decision log explain it.
 */
#[Fillable(['trip_id', 'rank', 'seat_number', 'crew_member_id', 'source', 'flag_reasons', 'decision_log'])]
class Assignment extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['seat_number' => 'integer', 'flag_reasons' => 'array', 'decision_log' => 'array'];
    }

    /**
     * The trip this seat belongs to.
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * The crew member filling the seat, if any.
     */
    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }
}
