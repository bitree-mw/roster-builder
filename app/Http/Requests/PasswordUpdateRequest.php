<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates a signed-in user changing their own password: the current password (checked by
 * AccountService) and a confirmed new one of at least 12 characters that differs from the current one.
 */
class PasswordUpdateRequest extends FormRequest
{
    /**
     * Any signed-in account may change its own password.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)],
        ];
    }
}
