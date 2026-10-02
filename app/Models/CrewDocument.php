<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A licence, medical or recurrent-training record with its expiry date (one per kind per crew member).
 * Expiry states are calculated by ExpiryService against the base-local date.
 */
#[Fillable(['crew_member_id', 'kind', 'expires_on'])]
class CrewDocument extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_on' => LocalDate::class];
    }

    /**
     * The crew member who holds the document.
     */
    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }
}
