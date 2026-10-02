<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual "do not assign this crew member to this trip" decision that the planner must respect on rebuild.
 */
#[Fillable(['trip_id', 'crew_member_id'])]
class Exclusion extends Model
{
    use HasFactory;

    /**
     * The trip the crew member is excluded from.
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * The excluded crew member.
     */
    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }
}
