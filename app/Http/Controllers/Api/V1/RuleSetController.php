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

class RuleSetController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(new RuleSetResource(RuleSet::findOrFail(1)));
    }

    public function update(RuleSetRequest $request, RuleSetService $service): JsonResponse
    {
        return ApiResponse::resource(new RuleSetResource($service->update($request->validated(), $request->user())), 'rules.updated');
    }
}
