<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The home page: guests are sent to sign in, staff land on the dashboard and crew on their own roster.
 */
class ExampleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_home_sends_each_audience_to_its_landing_page(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'scheduler']))->get('/')->assertRedirect('/dashboard');
        $this->actingAs(User::factory()->create(['role' => 'crew']))->get('/')->assertRedirect('/roster');
    }
}
