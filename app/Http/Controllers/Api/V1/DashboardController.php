<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardResource;
use App\Services\DashboardService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/dashboard — the operations dashboard: readiness counts, roster weeks, live roster conflicts,
 * today's trips and attention items (staff only).
 */
class DashboardController extends Controller
{
    public function __invoke(DashboardService $service): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new DashboardResource($service->summary()));
    }
}
