<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['trip_id', 'rank', 'seat_number', 'crew_member_id', 'source', 'flag_reasons', 'decision_log'])]
class Assignment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['seat_number' => 'integer', 'flag_reasons' => 'array', 'decision_log' => 'array'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }
}
