<?php

namespace App\Http\Resources;

use App\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of one seat on a trip: position, who holds it, whether the generator or a person put them
 * there, accepted rule problems (flag_reasons) and the decision log explaining the choice.
 *
 * @mixin Assignment
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,
            'trip_id' => $this->trip_id,
            'rank' => $this->rank,
            'seat_number' => $this->seat_number,
            'source' => $this->source,
            'crew_member_id' => $this->crew_member_id,
            'crew' => $this->whenLoaded('crewMember', fn (): ?array => $this->crewMember ? ['id' => $this->crewMember->id, 'name' => $this->crewMember->name, 'rank' => $this->crewMember->rank, 'base_airport' => $this->crewMember->base_airport] : null),
            'flag_reasons' => $this->flag_reasons ?? [],
            'decision_log' => $this->decision_log,
        ];
    }
}
