<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OverviewResource;
use App\Services\OverviewService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OverviewController extends Controller
{
    public function __invoke(OverviewService $service): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new OverviewResource($service->summary()));
    }
}
