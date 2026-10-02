<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AircraftRequest;
use App\Http\Requests\AircraftStatusRequest;
use App\Http\Resources\AircraftResource;
use App\Models\Aircraft;
use App\Services\AircraftService;
use App\Services\MaintenanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AircraftController extends Controller
{
    public function __construct(private AircraftService $service, private MaintenanceService $maintenance) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('read-operations');
        $aircraft = Aircraft::with('aircraftType')->orderBy('registration')->paginate(100);
        $this->maintenance->attachDueItems($aircraft->getCollection());

        return AircraftResource::collection($aircraft);
    }

    public function show(Aircraft $aircraft): AircraftResource
    {
        Gate::authorize('read-operations');
        $aircraft->load('aircraftType');
        $this->maintenance->attachDueItems([$aircraft]);

        return new AircraftResource($aircraft);
    }

    public function store(AircraftRequest $request): AircraftResource
    {
        return new AircraftResource($this->service->save($request->validated(), $request->user()));
    }

    public function update(AircraftRequest $request, Aircraft $aircraft): AircraftResource
    {
        return new AircraftResource($this->service->save($request->validated(), $request->user(), $aircraft));
    }

    public function status(AircraftStatusRequest $request, Aircraft $aircraft): AircraftResource
    {
        return new AircraftResource($this->service->changeStatus($aircraft, $request->validated('status'), $request->validated('reason'), $request->user()));
    }

    public function destroy(Request $request, Aircraft $aircraft): Response
    {
        Gate::authorize('manage-operations');
        $this->service->delete($aircraft, $request->user());

        return response()->noContent();
    }
}
