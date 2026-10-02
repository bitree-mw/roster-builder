<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LookupResource;
use App\Models\AircraftType;
use App\Models\Airport;
use Illuminate\Support\Facades\Gate;

class LookupController extends Controller
{
    public function __invoke(): LookupResource
    {
        Gate::authorize('read-operations');

        return new LookupResource(['airports' => Airport::orderBy('code')->get(), 'aircraft_types' => AircraftType::orderBy('code')->get()]);
    }
}
