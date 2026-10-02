<?php

namespace Database\Factories;

use App\Models\Aircraft;
use App\Models\MaintenanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenanceRecord> */
class MaintenanceRecordFactory extends Factory
{
    public function definition(): array
    {
        return ['aircraft_id' => Aircraft::factory(), 'kind' => 'a_check', 'title' => 'A-check', 'performed_on' => '2026-06-01', 'next_due_on' => '2027-06-01'];
    }
}
