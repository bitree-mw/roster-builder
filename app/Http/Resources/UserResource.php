<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe account fields only: never the password, remember token or API tokens.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'username' => $this->username, 'email' => $this->email, 'role' => $this->role, 'crew_member_id' => $this->crew_member_id];
    }
}
