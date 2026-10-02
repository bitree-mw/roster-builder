<?php

namespace App\Console\Commands;

use App\Models\CrewMember;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Operator tooling to create accounts (there is no public registration). The password is entered at a
 * hidden prompt, never as an argument, so it does not end up in shell history.
 *
 * Example: php artisan roster:create-user scheduler@example.com --name="Crew Scheduler" --username=scheduler --role=scheduler
 */
class CreateUser extends Command
{
    protected $signature = 'roster:create-user {email} {--name=} {--username= : Optional sign-in name (letters, numbers, dot, dash, underscore)} {--role=crew} {--crew-id=}';

    protected $description = 'Create an operator-managed account; password is entered securely';

    /**
     * Validate the details (12+ character password, unique email/username, crew role needs a crew profile)
     * and create the account. Role and links are set explicitly, never mass-assigned.
     */
    public function handle(): int
    {
        $data = ['email' => $this->argument('email'), 'name' => $this->option('name') ?: $this->ask('Full name'), 'username' => $this->option('username') !== null ? mb_strtolower(trim($this->option('username'))) : null, 'role' => $this->option('role'), 'crew_member_id' => $this->option('crew-id'), 'password' => $this->secret('Password (at least 12 characters)')];
        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'unique:users,email'], 'name' => ['required', 'string', 'max:150'],
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'role' => ['required', Rule::in(['scheduler', 'crew_control', 'crew'])],
            'crew_member_id' => ['nullable', 'required_if:role,crew', Rule::exists(CrewMember::class, 'id'), 'unique:users,crew_member_id'],
            'password' => ['required', Password::min(12)],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User;
        $user->name = $data['name'];
        $user->username = $data['username'];
        $user->email = $data['email'];
        $user->password = $data['password'];
        $user->role = $data['role'];
        $user->crew_member_id = $data['crew_member_id'];
        $user->save();
        $this->info('Account created.');

        return self::SUCCESS;
    }
}
