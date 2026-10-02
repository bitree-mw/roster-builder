<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Versioned JSON backup and restore of all operational data: airports, fleet and maintenance, crew with
 * ratings, documents and day planning, flight patterns, duty rules, roster weeks with trips, seats and
 * exclusions, and roster email logs.
 *
 * Never included: user accounts, passwords, sessions, API tokens, password reset tokens, the audit log,
 * cache and queue tables. A restore therefore keeps every account: links from accounts to crew profiles
 * are re-attached when the crew member exists in the backup, and references to users that do not exist
 * (who built or published a week, who recorded a check) are cleared.
 *
 * Restore is two steps: inspect() validates the file and returns record counts against the current data
 * (the file is kept privately under a token for 30 minutes); restore() then replaces everything in one
 * transaction and writes an audit entry. Any problem rolls the whole restore back.
 */
class BackupService
{
    public const FORMAT = 'malawi-roster-backup';

    public const VERSION = 1;

    /** Tables in foreign-key order (parents first); restore deletes in reverse order. */
    public const TABLES = ['airports', 'aircraft_types', 'aircraft', 'maintenance_records', 'rule_sets', 'crew_members', 'crew_ratings', 'crew_documents',
        'flights', 'flight_days', 'flight_legs', 'roster_periods', 'trips', 'assignments', 'exclusions', 'crew_activities', 'email_logs'];

    /** Columns that point at user accounts, cleared when the account does not exist. */
    private const USER_COLUMNS = ['maintenance_records' => ['recorded_by'], 'roster_periods' => ['built_by', 'published_by'], 'email_logs' => ['requested_by']];

    public function __construct(private AuditService $audit) {}

    /**
     * The whole backup document.
     *
     * @return array{format: string, version: int, created_at: string, created_by: string, tables: array<string, array<int, array<string, mixed>>>}
     */
    public function export(User $actor): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $query = DB::table($table);
            // Stable order so two backups of the same data are identical apart from the header.
            foreach ($this->keys($table) as $column) {
                $query->orderBy($column);
            }
            $tables[$table] = $query->get()->map(fn (object $row): array => (array) $row)->all();
        }
        $this->audit->event($actor, 'backed_up', 'backups', collect($tables)->map(fn (array $rows): int => count($rows))->all());

        return ['format' => self::FORMAT, 'version' => self::VERSION, 'created_at' => now('UTC')->toIso8601String(), 'created_by' => $actor->name, 'tables' => $tables];
    }

    /**
     * Validate an uploaded backup and keep it for restore().
     *
     * @return array{token: string, created_at: ?string, created_by: ?string, tables: array<int, array{table: string, backup: int, current: int}>}
     *
     * @throws ValidationException when the file is not a valid backup of this version
     */
    public function inspect(string $json, User $actor): array
    {
        $backup = $this->validate($json);
        // Checked backups that were never restored are removed after a day (they hold crew personal data).
        foreach (Storage::disk('local')->files('restores') as $file) {
            if (Storage::disk('local')->lastModified($file) < now()->subDay()->getTimestamp()) {
                Storage::disk('local')->delete($file);
            }
        }
        $token = (string) Str::uuid();
        Storage::disk('local')->put('restores/'.$token.'.json', $json);
        Cache::put('restore:'.$token, ['user_id' => $actor->id], now()->addMinutes(30));

        return ['token' => $token, 'created_at' => $backup['created_at'] ?? null, 'created_by' => $backup['created_by'] ?? null,
            'tables' => array_map(fn (string $table): array => ['table' => $table, 'backup' => count($backup['tables'][$table]), 'current' => DB::table($table)->count()], self::TABLES)];
    }

    /**
     * Replace all operational data with the inspected backup.
     *
     * @return array<string, int> restored row counts per table
     *
     * @throws ValidationException when the token has expired or belongs to someone else
     */
    public function restore(string $token, User $actor): array
    {
        $meta = Cache::get('restore:'.$token);
        $path = 'restores/'.$token.'.json';
        if ($meta === null || $meta['user_id'] !== $actor->id || ! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages(['token' => 'This restore has expired. Choose the backup file again.']);
        }
        $backup = $this->validate((string) Storage::disk('local')->get($path));
        $counts = DB::transaction(function () use ($backup, $actor): array {
            // Detach accounts from crew profiles, remembering the links to restore afterwards.
            $links = DB::table('users')->whereNotNull('crew_member_id')->pluck('crew_member_id', 'id')->all();
            DB::table('users')->whereNotNull('crew_member_id')->update(['crew_member_id' => null]);
            foreach (array_reverse(self::TABLES) as $table) {
                DB::table($table)->delete();
            }
            $users = DB::table('users')->pluck('id')->flip();
            $counts = [];
            foreach (self::TABLES as $table) {
                $rows = array_map(function (array $row) use ($table, $users): array {
                    foreach (self::USER_COLUMNS[$table] ?? [] as $column) {
                        if (isset($row[$column]) && ! $users->has($row[$column])) {
                            $row[$column] = null;
                        }
                    }

                    return $row;
                }, $backup['tables'][$table]);
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
                $counts[$table] = count($rows);
            }
            $crewIds = DB::table('crew_members')->pluck('id')->flip();
            foreach ($links as $userId => $crewId) {
                if ($crewIds->has($crewId)) {
                    DB::table('users')->where('id', $userId)->update(['crew_member_id' => $crewId]);
                }
            }
            $this->audit->event($actor, 'restored', 'backups', ['created_at' => $backup['created_at'] ?? null, 'created_by' => $backup['created_by'] ?? null, 'counts' => $counts]);

            return $counts;
        });
        Storage::disk('local')->delete($path);
        Cache::forget('restore:'.$token);

        return $counts;
    }

    /**
     * Parse and check a backup: format and version, every table present as a list of objects, and only
     * columns that exist in this database (nothing can be smuggled into other columns).
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(string $json): array
    {
        $backup = json_decode($json, true);
        if (! is_array($backup) || ($backup['format'] ?? null) !== self::FORMAT) {
            throw ValidationException::withMessages(['file' => 'This is not a Roster Builder backup file.']);
        }
        if (($backup['version'] ?? null) !== self::VERSION) {
            throw ValidationException::withMessages(['file' => 'This backup is version '.json_encode($backup['version'] ?? null).'; this system restores version '.self::VERSION.' only.']);
        }
        foreach (self::TABLES as $table) {
            $rows = $backup['tables'][$table] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw ValidationException::withMessages(['file' => 'The backup is missing the '.$table.' table.']);
            }
            $columns = array_flip(Schema::getColumnListing($table));
            foreach ($rows as $index => $row) {
                if (! is_array($row) || array_is_list($row) && $row !== []) {
                    throw ValidationException::withMessages(['file' => 'Row '.($index + 1).' of '.$table.' is not a record.']);
                }
                $unknown = array_diff_key($row, $columns);
                if ($unknown !== []) {
                    throw ValidationException::withMessages(['file' => 'The '.$table.' table has unknown column(s): '.implode(', ', array_keys($unknown)).'.']);
                }
                foreach ($row as $value) {
                    if (is_array($value) || is_object($value)) {
                        throw ValidationException::withMessages(['file' => 'Row '.($index + 1).' of '.$table.' contains a nested value.']);
                    }
                }
            }
        }

        return $backup;
    }

    /**
     * Ordering columns for a stable export: the primary key, or the composite key of pivot tables.
     *
     * @return array<int, string>
     */
    private function keys(string $table): array
    {
        return match ($table) {
            'airports' => ['code'],
            'crew_ratings' => ['crew_member_id', 'aircraft_type_id'],
            'flight_days' => ['flight_id', 'weekday'],
            default => ['id'],
        };
    }
}
