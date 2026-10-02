<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Browser sign-in/out: email or username (case-insensitive), generic errors, rate limiting and CSRF cookie.
 */
class SessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_rotates_session_and_logout_revokes_browser_access(): void
    {
        $user = User::factory()->create(['password' => 'test-password-123', 'role' => 'scheduler']);
        $this->postJson('/login', ['login' => $user->email, 'password' => 'test-password-123'])
            ->assertOk()->assertJsonPath('data.role', 'scheduler')->assertJsonMissingPath('data.password');
        $this->assertAuthenticatedAs($user);
        $this->postJson('/logout')->assertOk()->assertJsonPath('message', 'You have signed out.');
        $this->assertGuest();
    }

    public function test_login_accepts_username_case_insensitively(): void
    {
        $user = User::factory()->create(['username' => 'ops.desk', 'password' => 'test-password-123', 'role' => 'crew_control']);
        $this->postJson('/login', ['login' => ' Ops.Desk ', 'password' => 'test-password-123'])
            ->assertOk()->assertJsonPath('data.username', 'ops.desk');
        $this->assertAuthenticatedAs($user);
    }

    public function test_username_login_with_wrong_password_returns_generic_error(): void
    {
        User::factory()->create(['username' => 'ops.desk', 'password' => 'test-password-123']);
        $this->postJson('/login', ['login' => 'ops.desk', 'password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonPath('errors.login.0', 'The supplied credentials could not be verified.');
        $this->assertGuest();
    }

    public function test_invalid_credentials_return_generic_error(): void
    {
        $this->postJson('/login', ['login' => 'nobody@example.com', 'password' => 'invalid'])
            ->assertUnprocessable()->assertJsonPath('errors.login.0', 'The supplied credentials could not be verified.');
        $this->assertGuest();
    }

    public function test_missing_login_returns_422(): void
    {
        $this->postJson('/login', ['password' => 'invalid'])
            ->assertUnprocessable()->assertJsonPath('errors.login.0', 'Enter your email address or username.');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['login' => 'limited', 'password' => 'bad'])->assertUnprocessable();
        }
        $this->postJson('/login', ['login' => 'LIMITED', 'password' => 'bad'])->assertTooManyRequests();
    }

    public function test_browser_login_and_csrf_cookie_are_available(): void
    {
        $this->withoutVite();
        $this->get('/login')->assertSee('Sign in to your workspace')->assertSee('Email or username');
        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $this->get('/roster')->assertRedirect('/login');
    }
}
