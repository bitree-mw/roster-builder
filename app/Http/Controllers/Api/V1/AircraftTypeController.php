<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AircraftTypeRequest;
use App\Http\Resources\AircraftTypeResource;
use App\Models\AircraftType;
use App\Services\AircraftTypeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AircraftTypeController extends Controller
{
    public function __construct(private AircraftTypeService $service) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('read-operations');

        return AircraftTypeResource::collection(AircraftType::query()->withCount(['aircraft', 'aircraft as available_aircraft_count' => fn ($query) => $query->where('status', 'available')])->orderBy('code')->paginate(100));
    }

    public function show(AircraftType $aircraftType): AircraftTypeResource
    {
        Gate::authorize('read-operations');

        return new AircraftTypeResource($aircraftType);
    }

    public function store(AircraftTypeRequest $request): AircraftTypeResource
    {
        return new AircraftTypeResource($this->service->save($request->validated(), $request->user()));
    }

    public function update(AircraftTypeRequest $request, AircraftType $aircraftType): AircraftTypeResource
    {
        return new AircraftTypeResource($this->service->save($request->validated(), $request->user(), $aircraftType));
    }

    public function destroy(Request $request, AircraftType $aircraftType): Response
    {
        Gate::authorize('manage-operations');
        $this->service->delete($aircraftType, $request->user());

        return response()->noContent();
    }
}
