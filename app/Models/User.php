<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['scheduler', 'crew_control'], true);
    }

    public function initials(): string
    {
        return Str::of($this->name)->explode(' ')->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            'scheduler' => 'Scheduler',
            'crew_control' => 'Crew control',
            'crew' => 'Crew member',
            default => 'No access',
        };
    }
}
