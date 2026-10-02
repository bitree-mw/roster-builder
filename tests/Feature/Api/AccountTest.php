<?php

namespace Tests\Feature\Api;

use App\Models\CrewMember;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Account administration: administrators manage every kind of account, schedulers only pilot and cabin
 * crew accounts and never delete, crew control and crew have no access; at least one administrator always
 * remains; new passwords sign the person out; pilots and cabin crew can only read their roster.
 */
class AccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PASSWORD = 'a-long-password-123';

    public function test_admin_creates_every_kind_of_account_without_exposing_passwords(): void
    {
        $admin = $this->actingAsRole('admin');
        $captain = CrewMember::factory()->create(['rank' => 'CPT']);
        $cabin = CrewMember::factory()->create(['rank' => 'CC']);

        foreach (['admin', 'scheduler', 'crew_control'] as $role) {
            $this->postJson('/api/v1/accounts', $this->payload(['email' => $role.'@example.com', 'role' => $role]))->assertCreated()->assertJsonPath('data.type', $role);
        }
        $this->postJson('/api/v1/accounts', $this->payload(['email' => 'pilot@example.com', 'username' => 'Pilot.One', 'crew_member_id' => $captain->id]))->assertCreated()
            ->assertJsonPath('data.type', 'pilot')->assertJsonPath('data.role_label', 'Pilot')->assertJsonPath('data.username', 'pilot.one')->assertJsonMissingPath('data.password')
            ->assertJsonPath('message', 'Test Person can now sign in (pilot account).');
        $this->postJson('/api/v1/accounts', $this->payload(['email' => 'cabin@example.com', 'crew_member_id' => $cabin->id]))->assertCreated()->assertJsonPath('data.role_label', 'Cabin crew');

        $this->assertTrue(Hash::check(self::PASSWORD, User::firstWhere('email', 'pilot@example.com')->password));
        $this->assertDatabaseHas('audit_logs', ['entity' => 'users', 'action' => 'created', 'user_id' => $admin->id]);
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_scheduler_manages_only_pilot_and_cabin_accounts_and_cannot_delete(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $crew = CrewMember::factory()->create(['rank' => 'FO']);
        $this->actingAsRole('scheduler');

        $this->postJson('/api/v1/accounts', $this->payload(['role' => 'scheduler']))->assertUnprocessable()->assertJsonPath('errors.role.0', 'Schedulers can only create pilot and cabin crew accounts.');
        $id = $this->postJson('/api/v1/accounts', $this->payload(['crew_member_id' => $crew->id]))->assertCreated()->json('data.id');
        $this->putJson('/api/v1/accounts/'.$id, $this->payload(['name' => 'Renamed Officer', 'crew_member_id' => $crew->id, 'password' => null, 'password_confirmation' => null]))->assertOk()->assertJsonPath('data.name', 'Renamed Officer');
        $this->putJson('/api/v1/accounts/'.$admin->id, $this->payload(['role' => 'crew', 'crew_member_id' => $crew->id]))->assertForbidden();
        $this->deleteJson('/api/v1/accounts/'.$id)->assertForbidden();
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->assertModelExists(User::find($id));
    }

    public function test_crew_control_and_crew_have_no_account_access(): void
    {
        foreach (['crew_control', 'crew'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/v1/accounts')->assertForbidden();
            $this->postJson('/api/v1/accounts', $this->payload(['role' => 'crew', 'crew_member_id' => CrewMember::factory()->create()->id]))->assertForbidden();
        }
        $this->assertDatabaseCount('users', 2);
    }

    public function test_pilot_and_cabin_accounts_need_their_own_crew_profile(): void
    {
        $crew = CrewMember::factory()->create();
        User::factory()->create(['role' => 'crew', 'crew_member_id' => $crew->id]);
        $this->actingAsRole('admin');

        $this->postJson('/api/v1/accounts', $this->payload(['crew_member_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('crew_member_id');
        $this->postJson('/api/v1/accounts', $this->payload(['crew_member_id' => $crew->id]))->assertUnprocessable()->assertJsonPath('errors.crew_member_id.0', 'This crew member already has an account.');
        $this->postJson('/api/v1/accounts', $this->payload(['role' => 'scheduler', 'crew_member_id' => $crew->id]))->assertUnprocessable()->assertJsonValidationErrors('crew_member_id');
    }

    public function test_admin_deletes_others_but_never_themselves_or_the_last_admin(): void
    {
        $admin = $this->actingAsRole('admin');
        $other = User::factory()->create(['role' => 'scheduler']);
        $other->createToken('integration');

        $this->deleteJson('/api/v1/accounts/'.$admin->id)->assertUnprocessable()->assertJsonPath('errors.account.0', 'You cannot delete your own account.');
        $this->putJson('/api/v1/accounts/'.$admin->id, $this->payload(['email' => $admin->email, 'role' => 'scheduler', 'password' => null, 'password_confirmation' => null]))
            ->assertUnprocessable()->assertJsonPath('errors.role.0', 'You cannot remove your own administrator role.');
        $this->deleteJson('/api/v1/accounts/'.$other->id)->assertOk()->assertJsonPath('message', "Account for {$other->name} deleted. They can no longer sign in.");

        $this->assertModelMissing($other);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'users', 'entity_id' => $other->id, 'action' => 'deleted']);
    }

    public function test_new_password_from_an_admin_revokes_the_persons_tokens(): void
    {
        $this->actingAsRole('admin');
        $person = User::factory()->create(['role' => 'crew_control']);
        $person->createToken('integration');

        $this->putJson('/api/v1/accounts/'.$person->id, $this->payload(['email' => $person->email, 'role' => 'crew_control', 'password' => 'brand-new-password-1', 'password_confirmation' => 'brand-new-password-1']))->assertOk();
        $this->assertTrue(Hash::check('brand-new-password-1', $person->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_everyone_can_change_their_own_password_after_confirming_the_current_one(): void
    {
        $user = User::factory()->create(['role' => 'crew', 'password' => 'the-current-password']);
        Sanctum::actingAs($user, ['*']);

        $this->putJson('/api/v1/me/password', ['current_password' => 'wrong-password', 'password' => 'a-new-password-12', 'password_confirmation' => 'a-new-password-12'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/me/password', ['current_password' => 'the-current-password', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/me/password', ['current_password' => 'the-current-password', 'password' => 'a-new-password-12', 'password_confirmation' => 'a-new-password-12'])->assertOk();
        $this->assertTrue(Hash::check('a-new-password-12', $user->fresh()->password));
    }

    public function test_pilot_accounts_can_read_their_roster_but_change_nothing(): void
    {
        $captain = CrewMember::factory()->create(['rank' => 'CPT']);
        Sanctum::actingAs(User::factory()->create(['role' => 'crew', 'crew_member_id' => $captain->id]), ['*']);

        $this->getJson('/api/v1/roster-periods')->assertOk();
        $this->getJson('/api/v1/my-hours')->assertOk();
        $this->getJson('/api/v1/crew-members')->assertForbidden();
        $this->getJson('/api/v1/crew-hours')->assertForbidden();
        $this->postJson('/api/v1/roster-periods', ['week_start' => '2026-10-05'])->assertForbidden();
        $this->postJson('/api/v1/crew-activities', ['crew_member_id' => $captain->id, 'type' => 'leave', 'date_from' => '2026-10-05', 'date_to' => '2026-10-05'])->assertForbidden();
    }

    /** Act as a new user with the given role and a browser-equivalent token. */
    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'crew_member_id' => $role === 'crew' ? CrewMember::factory()->create()->id : null]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * A valid account payload (a crew account by default), with overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return ['name' => 'Test Person', 'email' => 'person@example.com', 'username' => null, 'role' => 'crew', 'crew_member_id' => null, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, ...$overrides];
    }
}
