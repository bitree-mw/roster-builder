<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RuleSetRequest;
use App\Http\Resources\RuleSetResource;
use App\Models\RuleSet;
use App\Services\RuleSetService;
use Illuminate\Support\Facades\Gate;

class RuleSetController extends Controller
{
    public function show(): RuleSetResource
    {
        Gate::authorize('read-operations');

        return new RuleSetResource(RuleSet::findOrFail(1));
    }

    public function update(RuleSetRequest $request, RuleSetService $service): RuleSetResource
    {
        return new RuleSetResource($service->update($request->validated(), $request->user()));
    }
}
