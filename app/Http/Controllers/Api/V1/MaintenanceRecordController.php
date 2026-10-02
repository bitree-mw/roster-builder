<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaintenanceQueryRequest;
use App\Http\Requests\MaintenanceRecordRequest;
use App\Http\Resources\MaintenanceAlertResource;
use App\Http\Resources\MaintenanceRecordResource;
use App\Models\MaintenanceRecord;
use App\Services\MaintenanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MaintenanceRecordController extends Controller
{
    public function __construct(private MaintenanceService $service) {}

    public function index(MaintenanceQueryRequest $request): AnonymousResourceCollection
    {
        return MaintenanceRecordResource::collection(MaintenanceRecord::with('aircraft.aircraftType', 'recorder')
            ->when($request->validated('aircraft_id'), fn ($query, $id) => $query->where('aircraft_id', $id))
            ->orderByDesc('performed_on')->orderByDesc('id')->paginate(100));
    }

    public function alerts(): AnonymousResourceCollection
    {
        Gate::authorize('read-operations');

        return MaintenanceAlertResource::collection($this->service->alerts());
    }

    public function show(MaintenanceRecord $maintenanceRecord): MaintenanceRecordResource
    {
        Gate::authorize('read-operations');

        return new MaintenanceRecordResource($maintenanceRecord->load('aircraft.aircraftType', 'recorder'));
    }

    public function store(MaintenanceRecordRequest $request): MaintenanceRecordResource
    {
        return new MaintenanceRecordResource($this->service->save($request->validated(), $request->user()));
    }

    public function update(MaintenanceRecordRequest $request, MaintenanceRecord $maintenanceRecord): MaintenanceRecordResource
    {
        return new MaintenanceRecordResource($this->service->save($request->validated(), $request->user(), $maintenanceRecord));
    }

    public function destroy(Request $request, MaintenanceRecord $maintenanceRecord): Response
    {
        Gate::authorize('manage-operations');
        $this->service->delete($maintenanceRecord, $request->user());

        return response()->noContent();
    }
}
