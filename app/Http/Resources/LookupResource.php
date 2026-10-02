<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LookupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['airports' => $this->resource['airports']->map(fn ($airport): array => ['code' => $airport->code, 'name' => $airport->name, 'is_base' => $airport->is_base])->all(),
            'aircraft_types' => AircraftTypeResource::collection($this->resource['aircraft_types'])];
    }
}
