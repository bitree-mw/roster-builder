<?php

namespace Database\Seeders;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fictional demonstration data for local development and tests: 2 aircraft types, 48 crew, 9 flight patterns,
 * 4 airframes with maintenance history, and an optional demo scheduler from DEMO_EMAIL / DEMO_PASSWORD.
 * Repeatable: existing records are left as they are. Refuses to run outside local/testing.
 */
class DemoSeeder extends Seeder
{
    /**
     * Seed everything in one transaction so a failure leaves no partial demo data.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data is restricted to local and testing environments.');
        }
        $this->call(DatabaseSeeder::class);
        DB::transaction(function (): void {
            $q400 = AircraftType::firstOrCreate(['code' => 'Q400'], ['cabin_crew_required' => 2, 'palette' => 'forest']);
            $b737 = AircraftType::firstOrCreate(['code' => 'B737'], ['cabin_crew_required' => 3, 'palette' => 'gold']);
            // Crew: every 4th person is based at BLZ; pilots alternate Q400/B737 ratings; cabin crew are all-aircraft.
            // Contracted working hours: 40 a week for pilots, 38 for cabin crew (the generator never exceeds them).
            // Crew #1 of each rank has a medical expiring mid-month, #2 leave, #3 a simulator session, #4 a standby.
            foreach (['CPT' => 12, 'FO' => 12, 'CC' => 24] as $rank => $count) {
                for ($number = 1; $number <= $count; $number++) {
                    $crew = CrewMember::firstOrCreate(['email' => strtolower($rank).$number.'@example.com'], ['name' => $rank.' Demo '.str_pad((string) $number, 2, '0', STR_PAD_LEFT), 'rank' => $rank, 'base_airport' => $number % 4 === 0 ? 'BLZ' : 'LLW', 'all_aircraft' => $rank === 'CC', 'weekly_hours' => $rank === 'CC' ? 38 : 40, 'active' => true]);
                    if (! $crew->wasRecentlyCreated) {
                        continue;
                    }
                    if ($rank !== 'CC') {
                        $crew->ratings()->sync([$number % 2 === 0 ? $b737->id : $q400->id]);
                    }
                    foreach (['licence', 'medical', 'recurrent'] as $kind) {
                        $crew->documents()->create(['kind' => $kind, 'expires_on' => $number === 1 && $kind === 'medical' ? now()->startOfMonth()->addDays(15) : now()->addYear()]);
                    }
                    if ($number === 2) {
                        $crew->activities()->create(['date' => now()->startOfMonth()->addDays(5), 'type' => 'leave']);
                    }
                    if ($number === 3) {
                        $date = now()->startOfMonth()->addDays(8)->format('Y-m-d');
                        $crew->activities()->create(['date' => $date, 'type' => 'sim', 'starts_at' => $date.' 06:00:00', 'ends_at' => $date.' 10:00:00', 'note' => 'Demo recurrent training']);
                    }
                    if ($number === 4) {
                        $date = now()->startOfMonth()->addDays(10)->format('Y-m-d');
                        $crew->activities()->create(['date' => $date, 'type' => 'standby', 'starts_at' => $date.' 03:00:00', 'ends_at' => $date.' 15:00:00', 'note' => 'Demo standby']);
                    }
                }
            }
            // Flight patterns: [code, base, destination, dep, arr, return dep, return arr, aircraft, return trip day].
            // LA1 returns on trip day 2, i.e. a night stop at ADD.
            $routes = [
                ['LB1', 'LLW', 'BLZ', '08:00', '09:00', '09:40', '10:40', $q400->id, 1],
                ['LB2', 'LLW', 'BLZ', '14:00', '15:00', '15:40', '16:40', $q400->id, 1],
                ['LN1', 'LLW', 'LUN', '08:00', '09:30', '10:20', '11:50', $q400->id, 1],
                ['LH1', 'LLW', 'HRE', '08:00', '09:30', '10:20', '11:50', $q400->id, 1],
                ['BJ1', 'BLZ', 'JNB', '08:00', '10:30', '11:30', '14:00', $b737->id, 1],
                ['LJ1', 'LLW', 'JNB', '08:00', '10:30', '11:30', '14:00', $b737->id, 1],
                ['LD1', 'LLW', 'DAR', '08:00', '10:00', '11:00', '13:00', $q400->id, 1],
                ['LK1', 'LLW', 'NBO', '08:00', '11:00', '12:00', '15:00', $b737->id, 1],
                ['LA1', 'LLW', 'ADD', '12:00', '16:00', '08:00', '12:00', $b737->id, 2],
            ];
            foreach ($routes as [$code, $base, $destination, $dep, $arr, $returnDep, $returnArr, $aircraftId, $returnDay]) {
                $flight = Flight::firstOrCreate(['code' => $code], ['aircraft_type_id' => $aircraftId, 'active' => true]);
                if (! $flight->wasRecentlyCreated) {
                    continue;
                }
                $flight->days()->createMany(array_map(fn (int $day): array => ['weekday' => $day], [0, 2, 4]));
                $flight->legs()->createMany([
                    ['trip_day' => 1, 'sequence' => 1, 'from_airport' => $base, 'to_airport' => $destination, 'departs_local' => $dep, 'arrives_local' => $arr],
                    ['trip_day' => $returnDay, 'sequence' => $returnDay === 1 ? 2 : 1, 'from_airport' => $destination, 'to_airport' => $base, 'departs_local' => $returnDep, 'arrives_local' => $returnArr],
                ]);
            }
            // Airframes covering every status, with overdue, due-soon (date and hours) and current maintenance examples.
            $airframes = [
                ['7Q-DMA', $q400->id, 'available', null, 18240.5],
                ['7Q-DMB', $q400->id, 'maintenance', 'Scheduled A-check in progress at LLW hangar', 21310.0],
                ['7Q-DMC', $b737->id, 'available', null, 40112.3],
                ['7Q-DMD', $b737->id, 'grounded', 'Demo AOG: awaiting replacement starter generator', 38770.8],
            ];
            foreach ($airframes as [$registration, $typeId, $status, $reason, $hours]) {
                $aircraft = Aircraft::firstOrNew(['registration' => $registration]);
                if ($aircraft->exists) {
                    continue;
                }
                $aircraft->fill(['aircraft_type_id' => $typeId, 'airframe_hours' => $hours, 'notes' => 'Fictional demonstration airframe.']);
                $aircraft->status = $status;
                $aircraft->status_reason = $reason;
                $aircraft->status_changed_at = now();
                $aircraft->save();
                $base = now()->startOfDay();
                $aircraft->maintenanceRecords()->createMany(match ($registration) {
                    '7Q-DMA' => [
                        ['kind' => 'a_check', 'title' => 'A-check 3A', 'performed_on' => $base->copy()->subMonths(3), 'airframe_hours_at' => $hours - 420, 'next_due_on' => $base->copy()->addDays(9), 'next_due_hours' => $hours + 180],
                        ['kind' => 'line_check', 'title' => 'Weekly check', 'performed_on' => $base->copy()->subDays(3), 'next_due_on' => $base->copy()->addDays(4)],
                    ],
                    '7Q-DMB' => [['kind' => 'a_check', 'title' => 'A-check 5A', 'performed_on' => $base->copy()->subMonths(4), 'airframe_hours_at' => $hours - 600, 'next_due_on' => $base->copy()->subDays(2)]],
                    '7Q-DMC' => [['kind' => 'c_check', 'title' => 'C-check 2C', 'performed_on' => $base->copy()->subYear(), 'airframe_hours_at' => $hours - 5900, 'next_due_hours' => $hours + 35]],
                    default => [['kind' => 'inspection', 'title' => 'Landing gear inspection', 'performed_on' => $base->copy()->subMonths(6), 'next_due_on' => $base->copy()->addMonths(6)]],
                });
            }
            // Optional demo account, only when both values are set locally; never a default credential.
            if (config('roster.demo_email') && config('roster.demo_password')) {
                $user = User::firstOrNew(['email' => config('roster.demo_email')]);
                if (! $user->exists) {
                    $user->name = 'Demo Scheduler';
                    $user->password = config('roster.demo_password');
                    $user->role = 'scheduler';
                    $user->save();
                }
            }
        });
    }
}
