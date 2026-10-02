<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates creating or editing an account. The role must be one the caller may assign (administrators:
 * any; schedulers: pilot or cabin crew only). Pilot and cabin crew accounts link to exactly one crew
 * profile; staff accounts never do. A password is required for new accounts and optional on edit
 * (blank keeps the current one).
 */
class AccountRequest extends FormRequest
{
    /**
     * Administrators and schedulers with write access; when editing, only accounts the caller may manage
     * (checked before validation so nothing about other accounts is revealed).
     */
    public function authorize(): bool
    {
        /** @var User|null $account */
        $account = $this->route('account');

        return ($this->user()?->can('manage-accounts') ?? false)
            && ($account === null || in_array($account->role, $this->user()->assignableRoles(), true));
    }

    /**
     * Usernames are stored lowercase, so compare them lowercase too (the unique check would otherwise miss
     * "Admin" vs "admin" on case-sensitive databases).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => mb_strtolower(trim($this->input('username')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User|null $account */
        $account = $this->route('account');

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')->ignore($account?->id)],
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/i', Rule::unique('users', 'username')->ignore($account?->id)],
            'role' => ['required', Rule::in($this->user()->assignableRoles())],
            'crew_member_id' => ['nullable', 'required_if:role,crew', 'prohibited_unless:role,crew', 'integer', 'exists:crew_members,id', Rule::unique('users', 'crew_member_id')->ignore($account?->id)],
            'password' => [$account ? 'nullable' : 'required', 'confirmed', Password::min(12)],
        ];
    }

    /**
     * Plain-language messages for the account rules people most often hit.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => $this->user()->isAdmin() ? 'Choose a valid account type.' : 'Schedulers can only create pilot and cabin crew accounts.',
            'crew_member_id.required_if' => 'Choose the crew member this pilot or cabin crew account belongs to.',
            'crew_member_id.unique' => 'This crew member already has an account.',
            'crew_member_id.prohibited_unless' => 'Only pilot and cabin crew accounts are linked to a crew member.',
            'username.regex' => 'Usernames may contain letters, numbers, dots, dashes and underscores only.',
        ];
    }
}
