<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One calendar month of rostering (draft or published). rules_snapshot freezes the duty rules in force when
 * the period was created so later rule changes never reinterpret it.
 */
#[Fillable(['month', 'status', 'rules_snapshot', 'published_at', 'published_by'])]
class RosterPeriod extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['month' => LocalDate::class, 'rules_snapshot' => 'array', 'published_at' => 'immutable_datetime'];
    }

    /**
     * Dated trips planned in this period.
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
