<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['crew_member_id', 'roster_period_id', 'sent_at', 'status'])]
class EmailLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }

    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }

    public function rosterPeriod(): BelongsTo
    {
        return $this->belongsTo(RosterPeriod::class);
    }
}
