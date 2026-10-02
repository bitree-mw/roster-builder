<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RuleSetRequest;
use App\Http\Resources\RuleSetResource;
use App\Models\RuleSet;
use App\Services\RuleSetService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/rules — the standard duty rule set. Staff can read; only schedulers can change it.
 */
class RuleSetController extends Controller
{
    /**
     * GET /rules
     */
    public function show(): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new RuleSetResource(RuleSet::findOrFail(1)));
    }

    /**
     * PUT /rules — applies to roster periods created afterwards.
     */
    public function update(RuleSetRequest $request, RuleSetService $service): JsonResponse
    {
        return ApiResponse::resource(new RuleSetResource($service->update($request->validated(), $request->user())), 'rules.updated');
    }
}
