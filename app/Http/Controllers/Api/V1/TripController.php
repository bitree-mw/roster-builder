<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TripAircraftRequest;
use App\Models\Trip;
use App\Services\TripAircraftService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/trips — the plane flying a dated trip (staff only). Seats are edited through /assignments.
 */
class TripController extends Controller
{
    public function __construct(private TripAircraftService $service) {}

    /**
     * GET /trips/{id}/aircraft-options — planes that could fly the trip: the flight plan's own first, each with
     * its status and any other trip it is already flying at that time.
     */
    public function aircraftOptions(Trip $trip): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(JsonResource::make($this->service->options($trip)));
    }

    /**
     * PUT /trips/{id}/aircraft {aircraft_id|null} — put a plane on the trip (kept on rebuild), or hand the choice
     * back to the roster build. The response carries the trip's aircraft; reload the roster to see seat changes.
     */
    public function aircraft(TripAircraftRequest $request, Trip $trip): JsonResponse
    {
        $trip = $this->service->change($trip, $request->validated('aircraft_id'), $request->user());
        $label = ($trip->schedule_snapshot['code'] ?? 'Trip').' on '.$trip->start_date->format('d M');
        $data = ['data' => ['id' => $trip->id, 'aircraft_id' => $trip->aircraft_id, 'aircraft_source' => $trip->aircraft_source, 'aircraft_label' => $trip->schedule_snapshot['aircraft_label'] ?? null]];

        return $trip->aircraft_id !== null
            ? ApiResponse::success('trip.aircraft_set', ['label' => $label, 'aircraft' => $trip->schedule_snapshot['aircraft_label'], 'cabin' => $trip->schedule_snapshot['cabin_crew_required']], $data)
            : ApiResponse::success('trip.aircraft_cleared', ['label' => $label], $data);
    }
}
