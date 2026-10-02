<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['roster_period_id', 'flight_id', 'start_date', 'schedule_snapshot'])]
class Trip extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['start_date' => LocalDate::class, 'schedule_snapshot' => 'array'];
    }

    public function rosterPeriod(): BelongsTo
    {
        return $this->belongsTo(RosterPeriod::class);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(Exclusion::class);
    }
}
