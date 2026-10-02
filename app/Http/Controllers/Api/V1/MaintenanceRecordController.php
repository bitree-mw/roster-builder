<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaintenanceQueryRequest;
use App\Http\Requests\MaintenanceRecordRequest;
use App\Http\Resources\MaintenanceAlertResource;
use App\Http\Resources\MaintenanceRecordResource;
use App\Models\MaintenanceRecord;
use App\Services\MaintenanceService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/maintenance-records and /api/v1/maintenance-alerts — the maintenance log and due alerts.
 */
class MaintenanceRecordController extends Controller
{
    public function __construct(private MaintenanceService $service) {}

    /**
     * GET /maintenance-records?aircraft_id= — newest first.
     */
    public function index(MaintenanceQueryRequest $request): JsonResponse
    {
        return ApiResponse::resource(MaintenanceRecordResource::collection(MaintenanceRecord::with('aircraft.aircraftType', 'recorder')
            ->when($request->validated('aircraft_id'), fn ($query, $id) => $query->where('aircraft_id', $id))
            ->orderByDesc('performed_on')->orderByDesc('id')->paginate(100)));
    }

    /**
     * GET /maintenance-alerts — overdue then due-soon items, most urgent first.
     */
    public function alerts(): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(MaintenanceAlertResource::collection($this->service->alerts()));
    }

    /**
     * GET /maintenance-records/{id}
     */
    public function show(MaintenanceRecord $maintenanceRecord): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new MaintenanceRecordResource($maintenanceRecord->load('aircraft.aircraftType', 'recorder')));
    }

    /**
     * POST /maintenance-records — the recorder is the signed-in user.
     */
    public function store(MaintenanceRecordRequest $request): JsonResponse
    {
        $record = $this->service->save($request->validated(), $request->user());

        return ApiResponse::created(new MaintenanceRecordResource($record), 'maintenance.created', $this->labels($record));
    }

    /**
     * PUT /maintenance-records/{id}
     */
    public function update(MaintenanceRecordRequest $request, MaintenanceRecord $maintenanceRecord): JsonResponse
    {
        $record = $this->service->save($request->validated(), $request->user(), $maintenanceRecord);

        return ApiResponse::resource(new MaintenanceRecordResource($record), 'maintenance.updated', $this->labels($record));
    }

    /**
     * DELETE /maintenance-records/{id} — labels are captured before deletion for the confirmation message.
     */
    public function destroy(Request $request, MaintenanceRecord $maintenanceRecord): JsonResponse
    {
        Gate::authorize('manage-operations');
        $labels = $this->labels($maintenanceRecord->load('aircraft'));
        $this->service->delete($maintenanceRecord, $request->user());

        return ApiResponse::success('maintenance.deleted', $labels);
    }

    /** @return array{label: string, aircraft: string} */
    private function labels(MaintenanceRecord $record): array
    {
        return ['label' => $record->title, 'aircraft' => $record->aircraft->registration];
    }
}
