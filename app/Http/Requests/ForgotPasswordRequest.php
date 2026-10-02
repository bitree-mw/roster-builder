<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ask for a password reset link by email address or username (guests only; throttled by route).
 */
class ForgotPasswordRequest extends FormRequest
{
    /**
     * Anyone may ask; the answer never reveals whether an account exists.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['login' => ['required', 'string', 'max:254']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['login.required' => 'Enter your email address or username.'];
    }
}
