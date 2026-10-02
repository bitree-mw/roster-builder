<?php

namespace App\Http\Resources;

use App\Services\ExpiryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrewMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'rank' => $this->rank,
            'base_airport' => $this->base_airport,
            'all_aircraft' => $this->all_aircraft,
            'active' => $this->active,
            'rating_ids' => $this->whenLoaded('ratings', fn (): array => $this->ratings->pluck('id')->all()),
            'ratings' => AircraftTypeResource::collection($this->whenLoaded('ratings')),
            'documents' => $this->whenLoaded('documents', fn (): array => $this->documents->map(fn ($document): array => [
                'kind' => $document->kind,
                'expires_on' => $document->expires_on->format('Y-m-d'),
                'state' => app(ExpiryService::class)->documentState($document->expires_on),
                'days_remaining' => app(ExpiryService::class)->daysUntil($document->expires_on),
            ])->all()),
        ];
    }
}
