<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Account administration. Administrators create, edit and delete every kind of account (including other
 * administrators). Schedulers create and edit pilot and cabin crew accounts only and never delete. Role,
 * username and crew link are set explicitly here, never mass-assigned.
 *
 * Whenever someone else sets a new password, or an account is deleted, that person's sessions and API
 * tokens are revoked so the old credentials stop working at once.
 */
class AccountService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Create an account, or update one the actor is allowed to manage.
     *
     * @param  array{name: string, email: string, username?: ?string, role: string, crew_member_id?: ?int, password?: ?string}  $data  validated AccountRequest payload
     *
     * @throws AuthorizationException when the actor may not manage the target account
     * @throws ValidationException when the change would leave no administrator
     */
    public function save(array $data, User $actor, ?User $account = null): User
    {
        return DB::transaction(function () use ($data, $actor, $account): User {
            if ($account !== null) {
                $account = User::query()->lockForUpdate()->findOrFail($account->id);
                $this->assertManageable($account, $actor);
                if ($account->role === 'admin' && $data['role'] !== 'admin') {
                    $this->assertNotLastAdmin($account, $actor, 'role');
                }
            }
            $account ??= new User;
            $before = $account->exists ? $account->toArray() : null;
            $account->name = $data['name'];
            $account->email = $data['email'];
            $account->username = isset($data['username']) && $data['username'] !== '' ? mb_strtolower(trim($data['username'])) : null;
            $account->role = $data['role'];
            // Only pilot and cabin crew accounts are linked to a crew profile.
            $account->crew_member_id = $data['role'] === 'crew' ? $data['crew_member_id'] : null;
            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
                $account->password = $data['password'];
            }
            $account->save();
            if ($before !== null && $passwordChanged && $account->id !== $actor->id) {
                $this->revokeAccess($account);
            }
            $this->audit->record($actor, $before === null ? 'created' : 'updated', $account, $before, [...$account->toArray(), 'password_changed' => $passwordChanged]);

            return $account->load('crewMember');
        });
    }

    /**
     * Delete an account (administrators only, never their own, never the last administrator). The audit log
     * keeps its history with the user reference cleared.
     *
     * @throws ValidationException when deleting would remove the actor or the last administrator
     */
    public function delete(User $account, User $actor): void
    {
        DB::transaction(function () use ($account, $actor): void {
            $account = User::query()->lockForUpdate()->findOrFail($account->id);
            if ($account->id === $actor->id) {
                throw ValidationException::withMessages(['account' => 'You cannot delete your own account.']);
            }
            if ($account->role === 'admin') {
                $this->assertNotLastAdmin($account, $actor, 'account');
            }
            $this->audit->record($actor, 'deleted', $account, $account->toArray());
            $this->revokeAccess($account);
            $account->delete();
        });
    }

    /**
     * Change the caller's own password after checking the current one. Other sessions and tokens of the
     * account are signed out; the current session stays signed in.
     *
     * @throws ValidationException when the current password is wrong
     */
    public function changeOwnPassword(User $user, string $current, string $password, ?string $currentSessionId): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is not correct.']);
        }
        DB::transaction(function () use ($user, $password, $currentSessionId): void {
            $user->password = $password;
            $user->save();
            $user->tokens()->delete();
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->when($currentSessionId, fn ($query, $id) => $query->where('id', '!=', $id))->delete();
            $this->audit->record($user, 'password_changed', $user);
        });
    }

    /**
     * Schedulers may only touch pilot and cabin crew accounts; administrators may touch any account.
     *
     * @throws AuthorizationException
     */
    public function assertManageable(User $account, User $actor): void
    {
        if (! in_array($account->role, $actor->assignableRoles(), true)) {
            throw new AuthorizationException('You can only manage pilot and cabin crew accounts.');
        }
    }

    /**
     * There must always be at least one administrator, and administrators cannot demote themselves.
     *
     * @throws ValidationException
     */
    private function assertNotLastAdmin(User $account, User $actor, string $field): void
    {
        if ($field === 'role' && $account->id === $actor->id) {
            throw ValidationException::withMessages(['role' => 'You cannot remove your own administrator role.']);
        }
        if (User::query()->where('role', 'admin')->count() <= 1) {
            throw ValidationException::withMessages([$field => 'At least one administrator account must remain.']);
        }
    }

    /** Sign the account out everywhere: database sessions and Sanctum tokens. */
    private function revokeAccess(User $account): void
    {
        $account->tokens()->delete();
        DB::table(config('session.table', 'sessions'))->where('user_id', $account->id)->delete();
    }
}
