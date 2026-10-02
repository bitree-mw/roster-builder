<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account in the Accounts page: safe fields only (never the password, remember token or API tokens),
 * the account type (admin, scheduler, crew_control, pilot or cabin) and the linked crew profile.
 *
 * @mixin User
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $crew = $this->relationLoaded('crewMember') ? $this->crewMember : null;

        return ['id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'role' => $this->role,
            'type' => $this->role === 'crew' ? ($crew?->rank === 'CC' ? 'cabin' : 'pilot') : $this->role,
            'role_label' => $this->roleLabel(),
            'crew_member_id' => $this->crew_member_id,
            'crew' => $crew ? ['id' => $crew->id, 'name' => $crew->name, 'rank' => $crew->rank, 'base_airport' => $crew->base_airport, 'active' => $crew->active] : null,
            'is_self' => $request->user()?->id === $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
