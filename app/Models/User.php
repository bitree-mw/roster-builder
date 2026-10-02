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

/**
 * An application account. role (scheduler, crew_control or crew), username and crew_member_id are
 * deliberately not fillable so they can never be mass-assigned from a request.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    /**
     * The crew profile linked to a crew account (crew users only see their own roster).
     */
    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }

    /**
     * Schedulers and crew control manage operational data; crew accounts do not.
     */
    public function isStaff(): bool
    {
        return in_array($this->role, ['scheduler', 'crew_control'], true);
    }

    /**
     * Up to two initials for the avatar in the header (e.g. "Chikondi Phiri" -> "CP").
     */
    public function initials(): string
    {
        return Str::of($this->name)->explode(' ')->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }

    /**
     * Human-readable role for the header and status bar; unknown roles read as having no access.
     */
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
