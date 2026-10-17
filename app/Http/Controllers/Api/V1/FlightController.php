<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlightRequest;
use App\Http\Requests\FlightStatusRequest;
use App\Http\Resources\FlightResource;
use App\Models\Flight;
use App\Models\RosterPeriod;
use App\Services\FlightService;
use App\Services\RosterScheduleService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/flights — flight patterns with legs, operating days and aircraft availability for their type.
 */
class FlightController extends Controller
{
    public function __construct(private FlightService $service, private RosterScheduleService $schedule) {}

    /**
     * GET /flights
     */
    public function index(): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(FlightResource::collection(Flight::with($this->relations())->orderBy('code')->paginate(100)));
    }

    /**
     * GET /flights/{id}
     */
    public function show(Flight $flight): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new FlightResource($flight->load($this->relations())));
    }

    /**
     * POST /flights — legs are validated against the duty rules before saving.
     */
    public function store(FlightRequest $request): JsonResponse
    {
        $flight = $this->service->save($request->validated(), $request->user());

        return ApiResponse::created(new FlightResource($flight->load($this->relations())), 'flight.created', ['label' => $flight->code]);
    }

    /**
     * PUT /flights/{id} — complete replacement including weekdays and legs. The message names any built roster
     * that now needs its route changes reviewed and confirmed.
     */
    public function update(FlightRequest $request, Flight $flight): JsonResponse
    {
        $flight = $this->service->save($request->validated(), $request->user(), $flight);

        return $this->withRosters($flight, 'flight.updated');
    }

    /**
     * PATCH /flights/{id}/status — enable or disable the pattern without resubmitting its legs. Rosters already
     * built are not changed here; the message names those that need the change reviewed and confirmed.
     */
    public function status(FlightStatusRequest $request, Flight $flight): JsonResponse
    {
        $flight = $this->service->setActive($flight, $request->boolean('active'), $request->user());

        return $this->withRosters($flight, $flight->active ? 'flight.enabled' : 'flight.disabled');
    }

    /**
     * DELETE /flights/{id} — 422 when the pattern has roster history (disable it instead).
     */
    public function destroy(Request $request, Flight $flight): JsonResponse
    {
        Gate::authorize('manage-operations');
        $this->service->delete($flight, $request->user());

        return ApiResponse::success('flight.deleted', ['label' => $flight->code]);
    }

    /**
     * The saved flight with its message. When built rosters that have not ended differ from the flight's new
     * schedule, the "_rosters" variant of the message names them (e.g. "week 42 (12–18 Oct 2026) and
     * November 2026"), so crew control knows which rosters to open, review and rebuild.
     *
     * @param  string  $messageKey  flight.updated | flight.enabled | flight.disabled
     */
    private function withRosters(Flight $flight, string $messageKey): JsonResponse
    {
        $rosters = $this->schedule->periodsAffectedBy($flight)->map(fn (RosterPeriod $period): string => $period->label())->all();
        $resource = new FlightResource($flight->load($this->relations()));
        if ($rosters === []) {
            return ApiResponse::resource($resource, $messageKey, ['label' => $flight->code]);
        }

        return ApiResponse::resource($resource, $messageKey.'_rosters', ['label' => $flight->code, 'rosters' => ucfirst(Arr::join($rosters, ', ', ' and '))]);
    }

    /** @return array<int|string, mixed> */
    private function relations(): array
    {
        return [
            'aircraftType' => fn ($query) => $query->withCount(['aircraft', 'aircraft as available_aircraft_count' => fn ($query) => $query->where('status', 'available')]),
            'legs',
            'days',
            'aircraft.aircraftType',
        ];
    }
}
