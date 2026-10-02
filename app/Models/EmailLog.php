<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery log for a roster email to one crew member (queued, sent or failed). Not yet used: email delivery
 * is a future milestone.
 */
#[Fillable(['crew_member_id', 'roster_period_id', 'sent_at', 'status'])]
class EmailLog extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }

    /**
     * The recipient.
     */
    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }

    /**
     * The roster period that was sent.
     */
    public function rosterPeriod(): BelongsTo
    {
        return $this->belongsTo(RosterPeriod::class);
    }
}
