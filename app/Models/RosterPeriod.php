<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['month', 'status', 'rules_snapshot', 'published_at', 'published_by'])]
class RosterPeriod extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['month' => LocalDate::class, 'rules_snapshot' => 'array', 'published_at' => 'immutable_datetime'];
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
