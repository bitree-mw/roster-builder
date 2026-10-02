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

class AircraftTypeController extends Controller
{
    public function __construct(private AircraftTypeService $service) {}

    public function index(): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(AircraftTypeResource::collection(AircraftType::query()->withCount(['aircraft', 'aircraft as available_aircraft_count' => fn ($query) => $query->where('status', 'available')])->orderBy('code')->paginate(100)));
    }

    public function show(AircraftType $aircraftType): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new AircraftTypeResource($aircraftType));
    }

    public function store(AircraftTypeRequest $request): JsonResponse
    {
        $type = $this->service->save($request->validated(), $request->user());

        return ApiResponse::created(new AircraftTypeResource($type), 'aircraft_type.created', ['label' => $type->code]);
    }

    public function update(AircraftTypeRequest $request, AircraftType $aircraftType): JsonResponse
    {
        $type = $this->service->save($request->validated(), $request->user(), $aircraftType);

        return ApiResponse::resource(new AircraftTypeResource($type), 'aircraft_type.updated', ['label' => $type->code]);
    }

    public function destroy(Request $request, AircraftType $aircraftType): JsonResponse
    {
        Gate::authorize('manage-operations');
        $this->service->delete($aircraftType, $request->user());

        return ApiResponse::success('aircraft_type.deleted', ['label' => $aircraftType->code]);
    }
}
