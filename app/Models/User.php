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
     * Account roles and their labels. Pilots and cabin crew both use the "crew" role (read-only access to
     * their own published roster); which one they are comes from the linked crew member's position.
     */
    public const ROLES = ['admin' => 'Administrator', 'scheduler' => 'Scheduler', 'crew_control' => 'Crew control', 'crew' => 'Crew member'];

    /**
     * Administrators, schedulers and crew control manage operational data; crew accounts do not.
     */
    public function isStaff(): bool
    {
        return in_array($this->role, ['admin', 'scheduler', 'crew_control'], true);
    }

    /**
     * Administrators manage every account, including other administrators.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Roles this user may give to accounts they create or edit: administrators any role, schedulers only
     * pilot and cabin crew accounts (role crew), everyone else none.
     *
     * @return array<int, string>
     */
    public function assignableRoles(): array
    {
        return match ($this->role) {
            'admin' => array_keys(self::ROLES),
            'scheduler' => ['crew'],
            default => [],
        };
    }

    /**
     * Up to two initials for the avatar in the header (e.g. "Chikondi Phiri" -> "CP").
     */
    public function initials(): string
    {
        return Str::of($this->name)->explode(' ')->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }

    /**
     * Human-readable role for the header, status bar and account list: crew accounts read as "Pilot" or
     * "Cabin crew" from their crew profile; unknown roles read as having no access.
     */
    public function roleLabel(): string
    {
        if ($this->role === 'crew' && $this->crew_member_id !== null) {
            return $this->loadMissing('crewMember')->crewMember?->rank === 'CC' ? 'Cabin crew' : 'Pilot';
        }

        return self::ROLES[$this->role] ?? 'No access';
    }
}
