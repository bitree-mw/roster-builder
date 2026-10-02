<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlightRequest;
use App\Http\Requests\FlightStatusRequest;
use App\Http\Resources\FlightResource;
use App\Models\Flight;
use App\Services\FlightService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class FlightController extends Controller
{
    public function __construct(private FlightService $service) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('read-operations');

        return FlightResource::collection(Flight::with($this->relations())->orderBy('code')->paginate(100));
    }

    public function show(Flight $flight): FlightResource
    {
        Gate::authorize('read-operations');

        return new FlightResource($flight->load($this->relations()));
    }

    public function store(FlightRequest $request): FlightResource
    {
        return new FlightResource($this->service->save($request->validated(), $request->user()));
    }

    public function update(FlightRequest $request, Flight $flight): FlightResource
    {
        return new FlightResource($this->service->save($request->validated(), $request->user(), $flight));
    }

    public function status(FlightStatusRequest $request, Flight $flight): FlightResource
    {
        return new FlightResource($this->service->setActive($flight, $request->boolean('active'), $request->user())->load($this->relations()));
    }

    public function destroy(Request $request, Flight $flight): Response
    {
        Gate::authorize('manage-operations');
        $this->service->delete($flight, $request->user());

        return response()->noContent();
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
