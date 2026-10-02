<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['crew_member_id', 'date', 'type', 'starts_at', 'ends_at', 'note'])]
class CrewActivity extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['date' => LocalDate::class, 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }
}
