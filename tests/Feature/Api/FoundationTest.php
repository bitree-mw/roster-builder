<?php

namespace Tests\Feature\Api;

use App\Models\AircraftType;
use App\Models\Airport;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\RuleSet;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Core API contract: authentication on every endpoint, role and token-scope authorization, aircraft type
 * CRUD with audit, crew ratings/documents and mass-assignment protection.
 */
class FoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * API endpoints that must reject anonymous callers.
     *
     * @return array<int, array{0: string}>
     */
    public static function protectedEndpoints(): array
    {
        return array_map(fn (string $path): array => [$path], ['aircraft-types', 'aircraft', 'maintenance-records', 'maintenance-alerts', 'overview', 'crew-members', 'flights', 'rules', 'roster-periods', 'lookups', 'me']);
    }

    #[DataProvider('protectedEndpoints')]
    public function test_anonymous_api_access_returns_401(string $path): void
    {
        $this->getJson('/api/v1/'.$path)->assertUnauthorized();
    }

    public function test_crew_cannot_read_staff_directory_or_write_aircraft(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'crew']), ['*']);
        $this->getJson('/api/v1/crew-members')->assertForbidden();
        $this->postJson('/api/v1/aircraft-types', ['code' => 'Q400', 'cabin_crew_required' => 2, 'palette' => 'forest'])->assertForbidden();
        $this->assertDatabaseCount('aircraft_types', 0);
    }

    public function test_scheduler_can_create_update_and_remove_aircraft_with_audit(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['roster:read', 'roster:write']);
        $id = $this->postJson('/api/v1/aircraft-types', ['code' => 'q400', 'cabin_crew_required' => 2, 'palette' => 'forest', 'id' => 99])
            ->assertCreated()->assertJsonPath('data.code', 'Q400')->json('data.id');
        $this->putJson('/api/v1/aircraft-types/'.$id, ['code' => 'Q400', 'cabin_crew_required' => 3, 'palette' => 'gold'])->assertOk()->assertJsonPath('data.cabin_crew_required', 3);
        $this->assertDatabaseHas('aircraft_types', ['id' => $id, 'cabin_crew_required' => 3]);
        $this->deleteJson('/api/v1/aircraft-types/'.$id)->assertOk()->assertJsonPath('message', 'Aircraft type Q400 removed.');
        $this->assertDatabaseMissing('aircraft_types', ['id' => $id]);
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_aircraft_validation_rejects_invalid_count_and_duplicate_code(): void
    {
        AircraftType::factory()->create(['code' => 'Q400']);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/aircraft-types', ['code' => 'Q400', 'cabin_crew_required' => 21, 'palette' => 'forest'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'cabin_crew_required']);
        $this->assertDatabaseCount('aircraft_types', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_aircraft_in_use_cannot_be_deleted(): void
    {
        $flight = Flight::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'crew_control']), ['*']);
        $this->deleteJson('/api/v1/aircraft-types/'.$flight->aircraft_type_id)->assertUnprocessable()->assertJsonValidationErrors('aircraft_type');
        $this->assertModelExists($flight);
    }

    public function test_read_only_token_cannot_write_even_for_scheduler(): void
    {
        $user = User::factory()->create(['role' => 'scheduler']);
        $token = $user->createToken('read integration', ['roster:read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/aircraft-types')->assertOk();
        $this->withToken($token)->postJson('/api/v1/aircraft-types', ['code' => 'Q400', 'cabin_crew_required' => 2, 'palette' => 'forest'])->assertForbidden();
        $this->assertDatabaseCount('aircraft_types', 0);
    }

    public function test_crew_control_cannot_change_rules(): void
    {
        RuleSet::factory()->create(['id' => 1]);
        Sanctum::actingAs(User::factory()->create(['role' => 'crew_control']), ['*']);
        $this->getJson('/api/v1/rules')->assertOk();
        $this->putJson('/api/v1/rules', [])->assertForbidden();
    }

    public function test_crew_ratings_and_documents_are_saved_and_unknown_privilege_fields_are_ignored(): void
    {
        $base = Airport::factory()->create();
        $aircraft = AircraftType::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $payload = ['name' => '<script>crew</script>', 'email' => 'pilot@example.com', 'rank' => 'CPT', 'base_airport' => $base->code, 'active' => true, 'all_aircraft' => false, 'rating_ids' => [$aircraft->id], 'documents' => [['kind' => 'licence', 'expires_on' => '2027-03-31']], 'role' => 'scheduler'];
        $id = $this->postJson('/api/v1/crew-members', $payload)->assertCreated()->assertJsonPath('data.rating_ids', [$aircraft->id])->json('data.id');
        $this->assertDatabaseHas('crew_documents', ['crew_member_id' => $id, 'kind' => 'licence', 'expires_on' => '2027-03-31']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_pilot_cannot_have_all_aircraft_rating(): void
    {
        $base = Airport::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->postJson('/api/v1/crew-members', ['name' => 'Pilot', 'rank' => 'FO', 'base_airport' => $base->code, 'active' => true, 'all_aircraft' => true, 'rating_ids' => [], 'documents' => []])
            ->assertUnprocessable()->assertJsonPath('errors.all_aircraft.0', 'Only cabin crew can be rated on all aircraft.');
        $this->assertDatabaseCount('crew_members', 0);
    }

    public function test_crew_own_profile_does_not_expose_peers(): void
    {
        $crew = CrewMember::factory()->create();
        $other = CrewMember::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'crew', 'crew_member_id' => $crew->id]), ['roster:read']);
        $this->getJson('/api/v1/my-profile')->assertOk()->assertJsonPath('data.id', $crew->id)->assertJsonMissing(['id' => $other->id, 'name' => $other->name]);
        $this->getJson('/api/v1/crew-members/'.$other->id)->assertForbidden();
    }
}
