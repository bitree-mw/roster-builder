<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Sign in with either an email address or a username in the single "login" field. */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('login'))) {
            $this->merge(['login' => trim($this->input('login'))]);
        }
    }

    public function rules(): array
    {
        return ['login' => ['required', 'string', 'max:254'], 'password' => ['required', 'string', 'max:1024']];
    }

    public function messages(): array
    {
        return ['login.required' => 'Enter your email address or username.'];
    }
}
