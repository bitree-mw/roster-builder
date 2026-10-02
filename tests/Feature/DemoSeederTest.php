<?php

namespace Tests\Feature;

use App\Models\AircraftType;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\RuleSet;
use App\Services\FlightTimelineService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The demo seeder is repeatable, creates the documented data set and its flight patterns pass validation.
 */
class DemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_seed_is_repeatable_and_patterns_pass_current_duty_validation(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        config(['roster.demo_email' => null, 'roster.demo_password' => null]);
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->assertDatabaseCount('aircraft_types', 2);
        $this->assertDatabaseCount('crew_members', 48);
        $this->assertDatabaseCount('flights', 9);
        $this->assertDatabaseCount('aircraft', 4);
        $this->assertDatabaseCount('maintenance_records', 5);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(12, CrewMember::where('rank', 'CPT')->count());
        $this->assertSame(3, AircraftType::where('code', 'B737')->first()->cabin_crew_required);
        $service = new FlightTimelineService;
        foreach (Flight::with('legs')->get() as $flight) {
            $result = $service->validate($flight->legs->toArray(), RuleSet::findOrFail(1));
            $this->assertNotEmpty($result['periods']);
        }
    }
}
