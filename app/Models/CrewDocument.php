<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['crew_member_id', 'kind', 'expires_on'])]
class CrewDocument extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['expires_on' => LocalDate::class];
    }

    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }
}
