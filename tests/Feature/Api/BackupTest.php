<?php

namespace Tests\Feature\Api;

use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\RosterScenario;
use Tests\TestCase;

/**
 * Backup and restore: the backup never contains accounts or secrets; a restore needs a checked file and
 * the typed confirmation, brings the data back exactly, keeps every account and its crew link, and is
 * refused for invalid files and for anyone but an administrator.
 */
class BackupTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RosterScenario;

    public function test_backup_round_trip_restores_data_and_keeps_accounts(): void
    {
        $this->scenario();
        $this->flight('LB1', [0, 2]);
        $captain = $this->crew('CPT', 'Alpha Captain');
        $this->crew('FO', 'Charlie Officer');
        $this->crew('CC', 'Delta Cabin');
        $pilot = User::factory()->create(['role' => 'crew', 'crew_member_id' => $captain->id, 'password' => 'pilot-password-1']);
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/v1/roster-periods/{$this->week()->id}/build")->assertOk();

        $json = $this->get('/api/v1/backups/download')->assertOk()->assertHeader('Content-Type', 'application/json; charset=UTF-8')->getContent();
        $backup = json_decode($json, true);
        $this->assertSame(['malawi-roster-backup', 1], [$backup['format'], $backup['version']]);
        $this->assertArrayNotHasKey('users', $backup['tables']);
        $this->assertStringNotContainsString('password', $json);
        $this->assertCount(6, $backup['tables']['assignments']);

        // Damage the data, then restore the backup.
        Flight::firstWhere('code', 'LB1')->update(['active' => false]);
        $captain->update(['name' => 'Renamed']);
        $this->crew('CC', 'Added Later');
        $inspect = $this->post('/api/v1/backups/inspect', ['file' => UploadedFile::fake()->createWithContent('backup.json', $json)], ['Accept' => 'application/json'])->assertOk();
        $crewRow = collect($inspect->json('data.tables'))->firstWhere('table', 'crew_members');
        $this->assertSame(['backup' => 3, 'current' => 4], ['backup' => $crewRow['backup'], 'current' => $crewRow['current']]);
        $this->postJson('/api/v1/backups/restore', ['token' => $inspect->json('data.token'), 'confirmation' => 'restore'])->assertUnprocessable()->assertJsonPath('errors.confirmation.0', 'Type RESTORE in capitals to confirm.');
        $this->postJson('/api/v1/backups/restore', ['token' => $inspect->json('data.token'), 'confirmation' => 'RESTORE'])->assertOk();

        $this->assertTrue(Flight::firstWhere('code', 'LB1')->active);
        $this->assertSame('Alpha Captain', CrewMember::find($captain->id)->name);
        $this->assertDatabaseMissing('crew_members', ['name' => 'Added Later']);
        $this->assertDatabaseCount('assignments', 6);
        $this->assertSame($captain->id, $pilot->fresh()->crew_member_id);
        $this->assertModelExists($admin);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'backups', 'action' => 'restored']);
    }

    public function test_invalid_files_and_non_admins_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['*']);
        $upload = fn (string $content) => $this->post('/api/v1/backups/inspect', ['file' => UploadedFile::fake()->createWithContent('backup.json', $content)], ['Accept' => 'application/json']);
        $upload('{"hello":"world"}')->assertUnprocessable()->assertJsonPath('errors.file.0', 'This is not a Roster Builder backup file.');
        $upload('{"format":"malawi-roster-backup","version":9,"tables":{}}')->assertUnprocessable()->assertJsonPath('errors.file.0', 'This backup is version 9; this system restores version 1 only.');
        $tables = array_fill_keys(BackupService::TABLES, []);
        $tables['airports'] = [['code' => 'LLW', 'name' => 'Lilongwe', 'is_admin' => 1]];
        $upload(json_encode(['format' => 'malawi-roster-backup', 'version' => 1, 'tables' => $tables]))->assertUnprocessable()->assertJsonPath('errors.file.0', 'The airports table has unknown column(s): is_admin.');

        Sanctum::actingAs(User::factory()->create(['role' => 'scheduler']), ['*']);
        $this->get('/api/v1/backups/download', ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson('/api/v1/backups/restore', ['token' => fake()->uuid(), 'confirmation' => 'RESTORE'])->assertForbidden();
    }
}
