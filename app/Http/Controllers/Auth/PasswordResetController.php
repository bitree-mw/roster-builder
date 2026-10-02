<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\PasswordResetService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Self-service password reset for guests: request a link by email or username, then set a new password
 * from the emailed link. JSON endpoints in the web stack (session + CSRF), throttled.
 */
class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $service) {}

    /**
     * POST /forgot-password {login} — always the same answer, whether or not the account exists.
     */
    public function send(ForgotPasswordRequest $request): JsonResponse
    {
        $this->service->sendLink($request->validated('login'));

        return ApiResponse::success('password.link_sent', ['minutes' => (int) config('auth.passwords.users.expire', 60)]);
    }

    /**
     * GET /reset-password/{token}?email= — the "choose a new password" page from the emailed link.
     */
    public function edit(string $token): View
    {
        return view('pages.reset-password', ['page' => 'reset-password', 'title' => 'Choose a new password', 'token' => $token, 'email' => (string) request()->query('email', '')]);
    }

    /**
     * POST /reset-password {token, email, password, password_confirmation}
     */
    public function update(ResetPasswordRequest $request): JsonResponse
    {
        $this->service->reset($request->only(['token', 'email', 'password', 'password_confirmation']));

        return ApiResponse::success('password.reset');
    }
}
