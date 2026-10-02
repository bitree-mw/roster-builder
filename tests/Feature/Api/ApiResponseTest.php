<?php

namespace Tests\Feature\Api;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Flight;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * The JSON envelope and error rendering from App\Support\Api: success messages, customisation through
 * config/api.php, error codes for 401/403/404/422/429/500, and that 500s never leak details without debug.
 */
class ApiResponseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_created_resource_uses_success_envelope_and_configured_message(): void
    {
        $type = AircraftType::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/aircraft', ['registration' => '7Q-TBA', 'aircraft_type_id' => $type->id, 'airframe_hours' => 0])
            ->assertCreated()->assertJsonPath('success', true)->assertJsonPath('message', '7Q-TBA registered and marked available.')->assertJsonPath('data.registration', '7Q-TBA');
    }

    public function test_paginated_list_keeps_links_and_meta_inside_envelope(): void
    {
        Aircraft::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['roster:read']);
        $this->getJson('/api/v1/aircraft')->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['success', 'data', 'links', 'meta'])->assertJsonMissingPath('message');
    }

    public function test_messages_and_envelope_extras_are_customisable_without_overriding_reserved_keys(): void
    {
        config([
            'api.messages.flight.disabled' => 'Pattern :label parked.',
            'api.envelope.extra' => ['api_version' => 'v1', 'data' => 'must not replace data'],
        ]);
        $flight = Flight::factory()->create(['code' => 'LB9']);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->patchJson('/api/v1/flights/'.$flight->id.'/status', ['active' => false])
            ->assertOk()->assertJsonPath('message', 'Pattern LB9 parked.')->assertJsonPath('api_version', 'v1')->assertJsonPath('data.code', 'LB9');
    }

    public function test_missing_model_returns_404_naming_the_resource(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['roster:read']);
        $this->getJson('/api/v1/maintenance-records/999')->assertNotFound()->assertExactJson([
            'success' => false,
            'message' => 'The requested maintenance record could not be found. It may have been removed.',
            'code' => 'not_found',
        ]);
    }

    public function test_unauthenticated_request_returns_401_code(): void
    {
        $this->getJson('/api/v1/aircraft')->assertUnauthorized()
            ->assertJsonPath('success', false)->assertJsonPath('code', 'unauthenticated')->assertJsonPath('message', 'Your session has ended. Sign in again to continue.');
    }

    public function test_forbidden_request_returns_403_code(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'crew']), ['*']);
        $this->getJson('/api/v1/aircraft')->assertForbidden()
            ->assertJsonPath('code', 'forbidden')->assertJsonPath('message', 'You do not have permission to do that.');
    }

    public function test_validation_error_uses_first_field_message_and_keeps_field_errors(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $response = $this->patchJson('/api/v1/aircraft/'.Aircraft::factory()->create()->id.'/status', ['status' => 'grounded']);
        $response->assertUnprocessable()->assertJsonPath('success', false)->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('message', 'Give a reason when an aircraft is not available.')->assertJsonPath('errors.reason.0', 'Give a reason when an aircraft is not available.');
    }

    public function test_throttled_login_returns_429_code_with_retry_after_header(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['login' => 'throttled', 'password' => 'bad']);
        }
        $this->postJson('/login', ['login' => 'throttled', 'password' => 'bad'])->assertTooManyRequests()
            ->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After');
    }

    public function test_server_error_hides_exception_details_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('api/v1/test-failure', fn () => throw new RuntimeException('database password is hunter2'));
        $response = $this->getJson('/api/v1/test-failure');
        $response->assertServerError()->assertJsonPath('code', 'server_error')->assertJsonMissingPath('debug');
        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }

    public function test_server_error_includes_debug_details_only_when_debug_is_on(): void
    {
        config(['app.debug' => true, 'api.expose_debug' => true]);
        Route::middleware('api')->get('api/v1/test-failure', fn () => throw new RuntimeException('trace me'));
        $this->getJson('/api/v1/test-failure')->assertServerError()
            ->assertJsonPath('debug.exception', RuntimeException::class)->assertJsonPath('debug.message', 'trace me');
    }

    public function test_browser_page_errors_still_render_html(): void
    {
        $response = $this->get('/no-such-page');
        $response->assertNotFound();
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }
}
