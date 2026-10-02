<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlightRequest;
use App\Http\Requests\FlightStatusRequest;
use App\Http\Resources\FlightResource;
use App\Models\Flight;
use App\Services\FlightService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FlightController extends Controller
{
    public function __construct(private FlightService $service) {}

    public function index(): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(FlightResource::collection(Flight::with($this->relations())->orderBy('code')->paginate(100)));
    }

    public function show(Flight $flight): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new FlightResource($flight->load($this->relations())));
    }

    public function store(FlightRequest $request): JsonResponse
    {
        $flight = $this->service->save($request->validated(), $request->user());

        return ApiResponse::created(new FlightResource($flight->load($this->relations())), 'flight.created', ['label' => $flight->code]);
    }

    public function update(FlightRequest $request, Flight $flight): JsonResponse
    {
        $flight = $this->service->save($request->validated(), $request->user(), $flight);

        return ApiResponse::resource(new FlightResource($flight->load($this->relations())), 'flight.updated', ['label' => $flight->code]);
    }

    public function status(FlightStatusRequest $request, Flight $flight): JsonResponse
    {
        $flight = $this->service->setActive($flight, $request->boolean('active'), $request->user());

        return ApiResponse::resource(new FlightResource($flight->load($this->relations())), $flight->active ? 'flight.enabled' : 'flight.disabled', ['label' => $flight->code]);
    }

    public function destroy(Request $request, Flight $flight): JsonResponse
    {
        Gate::authorize('manage-operations');
        $this->service->delete($flight, $request->user());

        return ApiResponse::success('flight.deleted', ['label' => $flight->code]);
    }

    /** @return array<int|string, mixed> */
    private function relations(): array
    {
        return [
            'aircraftType' => fn ($query) => $query->withCount(['aircraft', 'aircraft as available_aircraft_count' => fn ($query) => $query->where('status', 'available')]),
            'legs',
            'days',
        ];
    }
}
