<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AirportRequest;
use App\Http\Resources\AirportResource;
use App\Models\Airport;
use App\Services\AirportService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/airports — the airports flight routes can use (e.g. Entebbe, EBB) and which of them are crew bases.
 * Staff read the list; only administrators add, edit or remove airports.
 */
class AirportController extends Controller
{
    public function __construct(private AirportService $service) {}

    /**
     * GET /airports — every airport by code, with how many crew are based there and how many legs use it.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(AirportResource::collection(Airport::query()->withCount(['crewMembers', 'departingLegs', 'arrivingLegs'])->orderBy('code')->get()));
    }

    /**
     * POST /airports — 201 with "Airport EBB (Entebbe) added."
     */
    public function store(AirportRequest $request): JsonResponse
    {
        $airport = $this->service->create($request->validated(), $request->user());

        return ApiResponse::created(new AirportResource($airport), 'airport.created', ['label' => $airport->code.' ('.$airport->name.')']);
    }

    /**
     * PUT /airports/{code} — name, UTC offset and base flag (the code itself cannot change).
     */
    public function update(AirportRequest $request, Airport $airport): JsonResponse
    {
        $airport = $this->service->update($airport, $request->validated(), $request->user());

        return ApiResponse::resource(new AirportResource($airport), 'airport.updated', ['label' => $airport->code.' ('.$airport->name.')']);
    }

    /**
     * DELETE /airports/{code} — 422 while flight routes or crew bases use the airport.
     */
    public function destroy(Request $request, Airport $airport): JsonResponse
    {
        Gate::authorize('manage-airports');
        $this->service->delete($airport, $request->user());

        return ApiResponse::success('airport.deleted', ['label' => $airport->code]);
    }
}
