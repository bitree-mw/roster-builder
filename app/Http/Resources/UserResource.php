<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'username' => $this->username, 'email' => $this->email, 'role' => $this->role, 'crew_member_id' => $this->crew_member_id];
    }
}
