<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Self-service password reset: a link by email or username, the same answer for unknown accounts, a new
 * password from a valid link that signs the person out everywhere, and invalid links refused.
 */
class PasswordResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reset_link_is_sent_by_username_and_unknown_accounts_get_the_same_answer(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'pilot@example.com']);
        $user->forceFill(['username' => 'pilot.one'])->save();

        $answer = $this->postJson('/forgot-password', ['login' => 'Pilot.One'])->assertOk()->json('message');
        Notification::assertSentTo($user, ResetPassword::class);
        $this->postJson('/forgot-password', ['login' => 'nobody@example.com'])->assertOk()->assertJsonPath('message', $answer);
        Notification::assertCount(1);
        $this->get('/forgot-password')->assertOk()->assertViewIs('pages.forgot-password');
    }

    public function test_valid_link_sets_a_new_password_and_signs_out_everywhere(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'pilot@example.com']);
        $user->createToken('phone');
        $this->postJson('/forgot-password', ['login' => 'pilot@example.com'])->assertOk();
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->get('/reset-password/'.$token.'?email=pilot@example.com')->assertOk()->assertSee('pilot@example.com');
        $this->postJson('/reset-password', ['token' => 'wrong', 'email' => 'pilot@example.com', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'This reset link is invalid or has expired. Ask for a new one.');
        $this->postJson('/reset-password', ['token' => $token, 'email' => 'pilot@example.com', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])
            ->assertOk()->assertJsonPath('message', 'Your password has been reset. Sign in with your new password.');

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'users', 'action' => 'password_reset']);
        // The link works once.
        $this->postJson('/reset-password', ['token' => $token, 'email' => 'pilot@example.com', 'password' => 'another-password-1', 'password_confirmation' => 'another-password-1'])->assertUnprocessable();
    }
}
