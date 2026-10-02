<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property array<string, array<string, int>> $resource */
class OverviewResource extends JsonResource
{
    /**
     * Overview counts grouped as crew, flights, fleet and maintenance.
     *
     * @return array<string, array<string, int>>
     */
    public function toArray(Request $request): array
    {
        return [
            'crew' => $this->resource['crew'],
            'flights' => $this->resource['flights'],
            'fleet' => $this->resource['fleet'],
            'maintenance' => $this->resource['maintenance'],
        ];
    }
}
