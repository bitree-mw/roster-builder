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

/**
 * /api/v1/aircraft — individual airframes, their availability status and current maintenance due items.
 */
class AircraftController extends Controller
{
    public function __construct(private AircraftService $service, private MaintenanceService $maintenance) {}

    /**
     * GET /aircraft — every airframe with its type and maintenance due items (one query for all due items).
     */
    public function index(): JsonResponse
    {
        Gate::authorize('read-operations');
        $aircraft = Aircraft::with('aircraftType')->orderBy('registration')->paginate(100);
        $this->maintenance->attachDueItems($aircraft->getCollection());

        return ApiResponse::resource(AircraftResource::collection($aircraft));
    }

    /**
     * GET /aircraft/{id}
     */
    public function show(Aircraft $aircraft): JsonResponse
    {
        Gate::authorize('read-operations');
        $aircraft->load('aircraftType');
        $this->maintenance->attachDueItems([$aircraft]);

        return ApiResponse::resource(new AircraftResource($aircraft));
    }

    /**
     * POST /aircraft — registers an airframe; it always starts available.
     */
    public function store(AircraftRequest $request): JsonResponse
    {
        $aircraft = $this->service->save($request->validated(), $request->user());

        return ApiResponse::created(new AircraftResource($aircraft), 'aircraft.created', ['label' => $aircraft->registration]);
    }

    /**
     * PUT /aircraft/{id} — registration, type, hours and notes (not status).
     */
    public function update(AircraftRequest $request, Aircraft $aircraft): JsonResponse
    {
        $aircraft = $this->service->save($request->validated(), $request->user(), $aircraft);

        return ApiResponse::resource(new AircraftResource($aircraft), 'aircraft.updated', ['label' => $aircraft->registration]);
    }

    /**
     * PATCH /aircraft/{id}/status — mark available, in maintenance, grounded (AOG) or unavailable, with a reason.
     */
    public function status(AircraftStatusRequest $request, Aircraft $aircraft): JsonResponse
    {
        $aircraft = $this->service->changeStatus($aircraft, $request->validated('status'), $request->validated('reason'), $request->user());

        return ApiResponse::resource(new AircraftResource($aircraft), 'aircraft.status', [
            'label' => $aircraft->registration,
            'status' => lcfirst(Aircraft::STATUSES[$aircraft->status]),
        ]);
    }

    /**
     * DELETE /aircraft/{id} — 422 when maintenance history exists.
     */
    public function destroy(Request $request, Aircraft $aircraft): JsonResponse
    {
        Gate::authorize('manage-operations');
        $this->service->delete($aircraft, $request->user());

        return ApiResponse::success('aircraft.deleted', ['label' => $aircraft->registration]);
    }
}
