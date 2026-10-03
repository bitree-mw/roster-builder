<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use RuntimeException;
use Tests\TestCase;

/**
 * Hosting safeguards: the browser session is accepted on whatever domain the app is hosted on (a copied
 * development SANCTUM_STATEFUL_DOMAINS cannot lock it out, while other sites' pages are still not trusted),
 * the deployment doctor spots cPanel mail placeholders left in .env, and unexpected server errors carry a
 * reference that matches the log entry.
 */
class DeploymentTest extends TestCase
{
    public function test_the_hosted_domain_is_always_a_stateful_front_end(): void
    {
        // The loaded config already merges a development SANCTUM_STATEFUL_DOMAINS list; roster.example.com is not in it.
        $own = Request::create('https://roster.example.com/api/v1/me', 'GET', server: ['HTTP_REFERER' => 'https://roster.example.com/dashboard']);
        $foreign = Request::create('https://roster.example.com/api/v1/me', 'GET', server: ['HTTP_REFERER' => 'https://attacker.example.net/page']);

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($own));
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($foreign));
    }

    public function test_doctor_flags_unreplaced_cpanel_mail_placeholders(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.your-domain.com', 'mail.mailers.smtp.username' => 'roster@your-domain.com']);
        $this->artisan('roster:doctor')->expectsOutputToContain('your-domain.com placeholders')->run();

        config(['mail.mailers.smtp.host' => 'mail.malawian-airlines.example', 'mail.mailers.smtp.username' => 'roster@malawian-airlines.example', 'mail.from.address' => 'roster@malawian-airlines.example']);
        $this->artisan('roster:doctor')->doesntExpectOutputToContain('your-domain.com placeholders')->run();
    }

    public function test_server_errors_return_a_reference_that_is_logged(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/_deployment-test-failure', fn () => throw new RuntimeException('Database is read-only'));

        $response = $this->getJson('/api/v1/_deployment-test-failure')->assertStatus(500)->assertJsonPath('code', 'server_error')->assertJsonMissingPath('debug');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $response->json('reference'));
        $this->assertStringNotContainsString('read-only', $response->getContent());
    }
}
