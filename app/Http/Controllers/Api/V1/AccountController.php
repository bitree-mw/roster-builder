<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Services\AccountService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/accounts — account administration. Administrators see and manage every account; schedulers see,
 * create and edit pilot and cabin crew accounts only, and cannot delete. Crew control has no access.
 */
class AccountController extends Controller
{
    public function __construct(private AccountService $service) {}

    /**
     * GET /accounts — the accounts the caller may manage, ordered by name.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-accounts');
        $accounts = User::query()->whereIn('role', $request->user()->assignableRoles())->with('crewMember')->orderBy('name')->orderBy('id')->paginate(200);

        return ApiResponse::resource(AccountResource::collection($accounts));
    }

    /**
     * POST /accounts
     */
    public function store(AccountRequest $request): JsonResponse
    {
        $account = $this->service->save($request->validated(), $request->user());

        return ApiResponse::created(new AccountResource($account), 'account.created', ['label' => $account->name, 'type' => mb_strtolower($account->roleLabel())]);
    }

    /**
     * PUT /accounts/{id} — blank password keeps the current one; a new one signs the person out everywhere.
     */
    public function update(AccountRequest $request, User $account): JsonResponse
    {
        $account = $this->service->save($request->validated(), $request->user(), $account);

        return ApiResponse::resource(new AccountResource($account), 'account.updated', ['label' => $account->name]);
    }

    /**
     * DELETE /accounts/{id} — administrators only.
     */
    public function destroy(Request $request, User $account): JsonResponse
    {
        Gate::authorize('delete-accounts');
        $this->service->delete($account, $request->user());

        return ApiResponse::success('account.deleted', ['label' => $account->name]);
    }
}
