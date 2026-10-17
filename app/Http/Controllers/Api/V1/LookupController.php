<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LookupResource;
use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Airport;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/lookups — airports (with base flag), aircraft types and registered planes for form dropdowns.
 */
class LookupController extends Controller
{
    public function __invoke(): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new LookupResource(['airports' => Airport::orderBy('code')->get(), 'aircraft_types' => AircraftType::orderBy('code')->get(),
            'aircraft' => Aircraft::query()->with('aircraftType')->orderBy('registration')->get()]));
    }
}
