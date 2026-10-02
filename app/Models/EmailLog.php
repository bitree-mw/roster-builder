<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery log for one roster email to one crew member: the address used, who asked for it, and whether it
 * is queued, sent (with the time) or failed (with the reason). Written by RosterEmailService and
 * the SendRosterEmail job.
 */
#[Fillable(['crew_member_id', 'roster_period_id', 'email', 'requested_by', 'sent_at', 'status', 'error'])]
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
