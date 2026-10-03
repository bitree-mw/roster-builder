<?php

namespace Tests\Feature\Api;

use App\Models\Airport;
use App\Models\CrewMember;
use App\Models\FlightLeg;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Airports as administrator settings: adding a route destination (e.g. Entebbe, EBB) that flight routes can
 * then use, validation of codes and offsets, administrators-only writes, the code staying fixed on edit,
 * and the guards that keep bases and in-use airports from being removed.
 */
class AirportTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Act as an administrator with a browser-equivalent token. */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_administrator_adds_an_airport_that_flight_routes_can_then_choose(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/airports', ['code' => ' ebb ', 'name' => ' Entebbe ', 'utc_offset_minutes' => 180, 'is_base' => false])->assertCreated()
            ->assertJsonPath('data.code', 'EBB')->assertJsonPath('data.name', 'Entebbe')
            ->assertJsonPath('message', 'Airport EBB (Entebbe) added. It can now be used on flight routes.');

        $this->getJson('/api/v1/lookups')->assertOk()->assertJsonFragment(['code' => 'EBB', 'name' => 'Entebbe', 'is_base' => false]);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'airports', 'action' => 'created']);
    }

    public function test_airport_code_offset_and_duplicates_are_validated(): void
    {
        Airport::factory()->create(['code' => 'EBB']);
        $this->actingAsAdmin();
        $this->postJson('/api/v1/airports', ['code' => 'EBB', 'name' => 'Entebbe', 'utc_offset_minutes' => 180, 'is_base' => false])
            ->assertUnprocessable()->assertJsonPath('errors.code.0', 'An airport with this code already exists.');
        $this->postJson('/api/v1/airports', ['code' => 'E1', 'name' => 'Bad', 'utc_offset_minutes' => 190, 'is_base' => false])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Use the 3-letter IATA code (or 4-letter ICAO code), for example EBB.')
            ->assertJsonPath('errors.utc_offset_minutes.0', 'The UTC offset must be in quarter hours, for example +3 or +5:45.');
        $this->assertDatabaseCount('airports', 1);
    }

    public function test_only_administrators_change_airports_while_staff_can_list_them(): void
    {
        $airport = Airport::factory()->create(['code' => 'EBB', 'is_base' => false]);
        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->getJson('/api/v1/airports')->assertOk()->assertJsonPath('data.0.code', 'EBB');
        $this->postJson('/api/v1/airports', ['code' => 'NBO', 'name' => 'Nairobi', 'utc_offset_minutes' => 180, 'is_base' => false])->assertForbidden();
        $this->putJson('/api/v1/airports/EBB', ['name' => 'Renamed', 'utc_offset_minutes' => 180, 'is_base' => false])->assertForbidden();
        $this->deleteJson('/api/v1/airports/EBB')->assertForbidden();
        $this->assertDatabaseHas('airports', ['code' => $airport->code, 'name' => $airport->name]);
    }

    public function test_edit_keeps_the_code_and_a_base_with_crew_cannot_stop_being_a_base(): void
    {
        $base = Airport::factory()->create(['code' => 'LLW', 'is_base' => true]);
        CrewMember::factory()->create(['base_airport' => 'LLW']);
        $this->actingAsAdmin();

        $this->putJson('/api/v1/airports/LLW', ['code' => 'XXX', 'name' => 'Lilongwe Kamuzu', 'utc_offset_minutes' => 120, 'is_base' => true])->assertOk()
            ->assertJsonPath('data.code', 'LLW')->assertJsonPath('data.name', 'Lilongwe Kamuzu');
        $this->putJson('/api/v1/airports/LLW', ['name' => 'Lilongwe', 'utc_offset_minutes' => 120, 'is_base' => false])->assertUnprocessable()
            ->assertJsonPath('errors.is_base.0', '1 crew members are based at LLW and 0 flight routes start there. Move them before LLW stops being a crew base.');
        $this->assertDatabaseHas('airports', ['code' => $base->code, 'is_base' => true]);
        $this->assertDatabaseMissing('airports', ['code' => 'XXX']);
    }

    public function test_airports_in_use_cannot_be_removed_but_unused_ones_can(): void
    {
        $leg = FlightLeg::factory()->create();
        Airport::factory()->create(['code' => 'EBB', 'is_base' => false]);
        $this->actingAsAdmin();

        $this->deleteJson('/api/v1/airports/'.$leg->to_airport)->assertUnprocessable()
            ->assertJsonPath('errors.airport.0', $leg->to_airport.' is used by flight routes or as a crew base and cannot be removed.');
        $this->deleteJson('/api/v1/airports/EBB')->assertOk()->assertJsonPath('message', 'Airport EBB removed.');
        $this->assertDatabaseMissing('airports', ['code' => 'EBB']);
        $this->assertDatabaseHas('airports', ['code' => $leg->to_airport]);
    }
}
