<?php

namespace App\Models;

use App\Support\Roster\Section;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat (CPT, FO or a numbered cabin seat) on a rostered trip. A null crew member means open time.
 * source records whether the planner or a person made the assignment; flags and the decision log explain it.
 *
 * Captain seats can cover a section of the trip (from_leg–to_leg, legs numbered from 1); null means the whole
 * trip. A split trip has CPT seats 1, 2, … covering the legs in order. First officer and cabin seats always
 * cover the whole trip. Sections are set by RosterBuilderService and AssignmentService, never from a request.
 */
#[Fillable(['trip_id', 'rank', 'seat_number', 'from_leg', 'to_leg', 'crew_member_id', 'source', 'flag_reasons', 'decision_log'])]
class Assignment extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['seat_number' => 'integer', 'from_leg' => 'integer', 'to_leg' => 'integer', 'flag_reasons' => 'array', 'decision_log' => 'array'];
    }

    /** Whether the seat covers only part of its trip (a captain section). */
    public function isSection(): bool
    {
        return $this->from_leg !== null && $this->to_leg !== null && Section::isPartial($this->trip->schedule_snapshot ?? [], $this->from_leg, $this->to_leg);
    }

    /**
     * The duty this seat's holder actually flies: the trip's schedule snapshot, cut down to the seat's section
     * for a captain who flies only part of it (see Section::restrict()).
     *
     * @return array<string, mixed>
     */
    public function scheduleSnapshot(): array
    {
        return Section::restrict($this->trip->schedule_snapshot ?? [], $this->from_leg, $this->to_leg);
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
