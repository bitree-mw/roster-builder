<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Set a new password from a reset link: the link's token and email, and a confirmed password of at least
 * 12 characters.
 */
class ResetPasswordRequest extends FormRequest
{
    /**
     * Anyone holding a valid reset link.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ];
    }
}
