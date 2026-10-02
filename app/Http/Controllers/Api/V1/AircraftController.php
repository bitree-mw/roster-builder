<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AircraftRequest;
use App\Http\Requests\AircraftStatusRequest;
use App\Http\Resources\AircraftResource;
use App\Models\Aircraft;
use App\Services\AircraftService;
use App\Services\MaintenanceService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AircraftController extends Controller
{
    public function __construct(private AircraftService $service, private MaintenanceService $maintenance) {}

    public function index(): JsonResponse
    {
        Gate::authorize('read-operations');
        $aircraft = Aircraft::with('aircraftType')->orderBy('registration')->paginate(100);
        $this->maintenance->attachDueItems($aircraft->getCollection());

        return ApiResponse::resource(AircraftResource::collection($aircraft));
    }

    public function show(Aircraft $aircraft): JsonResponse
    {
        Gate::authorize('read-operations');
        $aircraft->load('aircraftType');
        $this->maintenance->attachDueItems([$aircraft]);

        return ApiResponse::resource(new AircraftResource($aircraft));
    }

    public function store(AircraftRequest $request): JsonResponse
    {
        $aircraft = $this->service->save($request->validated(), $request->user());

        return ApiResponse::created(new AircraftResource($aircraft), 'aircraft.created', ['label' => $aircraft->registration]);
    }

    public function update(AircraftRequest $request, Aircraft $aircraft): JsonResponse
    {
        $aircraft = $this->service->save($request->validated(), $request->user(), $aircraft);

        return ApiResponse::resource(new AircraftResource($aircraft), 'aircraft.updated', ['label' => $aircraft->registration]);
    }

    public function status(AircraftStatusRequest $request, Aircraft $aircraft): JsonResponse
    {
        $aircraft = $this->service->changeStatus($aircraft, $request->validated('status'), $request->validated('reason'), $request->user());

        return ApiResponse::resource(new AircraftResource($aircraft), 'aircraft.status', [
            'label' => $aircraft->registration,
            'status' => lcfirst(Aircraft::STATUSES[$aircraft->status]),
        ]);
    }

    public function destroy(Request $request, Aircraft $aircraft): JsonResponse
    {
        Gate::authorize('manage-operations');
        $this->service->delete($aircraft, $request->user());

        return ApiResponse::success('aircraft.deleted', ['label' => $aircraft->registration]);
    }
}
