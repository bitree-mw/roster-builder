<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AircraftTypeRequest;
use App\Http\Resources\AircraftTypeResource;
use App\Models\AircraftType;
use App\Services\AircraftTypeService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/aircraft-types — fleet types (Q400, B737...). Staff read with roster:read and write with roster:write.
 */
class AircraftTypeController extends Controller
{
    public function __construct(private AircraftTypeService $service) {}

    /**
     * GET /aircraft-types — all types with registered and available airframe counts.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(AircraftTypeResource::collection(AircraftType::query()->withCount(['aircraft', 'aircraft as available_aircraft_count' => fn ($query) => $query->where('status', 'available')])->orderBy('code')->paginate(100)));
    }

    /**
     * GET /aircraft-types/{id}
     */
    public function show(AircraftType $aircraftType): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new AircraftTypeResource($aircraftType));
    }

    /**
     * POST /aircraft-types — 201 with "Aircraft type … added."
     */
    public function store(AircraftTypeRequest $request): JsonResponse
    {
        $type = $this->service->save($request->validated(), $request->user());

        return ApiResponse::created(new AircraftTypeResource($type), 'aircraft_type.created', ['label' => $type->code]);
    }

    /**
     * PUT /aircraft-types/{id} — full replacement of the editable fields.
     */
    public function update(AircraftTypeRequest $request, AircraftType $aircraftType): JsonResponse
    {
        $type = $this->service->save($request->validated(), $request->user(), $aircraftType);

        return ApiResponse::resource(new AircraftTypeResource($type), 'aircraft_type.updated', ['label' => $type->code]);
    }

    /**
     * DELETE /aircraft-types/{id} — 422 while flights, ratings or airframes still use the type.
     */
    public function destroy(Request $request, AircraftType $aircraftType): JsonResponse
    {
        Gate::authorize('manage-operations');
        $this->service->delete($aircraftType, $request->user());

        return ApiResponse::success('aircraft_type.deleted', ['label' => $aircraftType->code]);
    }
}
