<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function roles(): array
    {
        return [['scheduler', true, true], ['crew_control', true, false], ['crew', false, false]];
    }

    #[DataProvider('roles')]
    public function test_gate_matrix_enforces_staff_and_scheduler_roles(string $role, bool $operations, bool $rules): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]), ['*']);
        $this->assertSame($operations, Gate::allows('read-operations'));
        $this->assertSame($operations, Gate::allows('manage-operations'));
        $this->assertSame($rules, Gate::allows('manage-rules'));
    }

    public function test_login_without_csrf_is_rejected_outside_test_bypass(): void
    {
        $this->app['env'] = 'local';
        $this->postJson('/login', ['login' => 'nobody@example.com', 'password' => 'not-secret'])
            ->assertStatus(419);
        $this->assertGuest();
    }

    public function test_stateful_api_mutation_without_csrf_is_rejected(): void
    {
        $this->app['env'] = 'local';
        $this->actingAs(User::factory()->create(['role' => 'scheduler']));
        $this->withHeader('Origin', 'http://roster-builder.test')->postJson('/api/v1/aircraft-types', ['code' => 'Q400', 'cabin_crew_required' => 2, 'palette' => 'forest'])
            ->assertStatus(419);
        $this->assertDatabaseCount('aircraft_types', 0);
    }
}
