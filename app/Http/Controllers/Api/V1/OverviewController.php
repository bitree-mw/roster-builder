<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OverviewResource;
use App\Services\OverviewService;
use Illuminate\Support\Facades\Gate;

class OverviewController extends Controller
{
    public function __invoke(OverviewService $service): OverviewResource
    {
        Gate::authorize('read-operations');

        return new OverviewResource($service->summary());
    }
}
