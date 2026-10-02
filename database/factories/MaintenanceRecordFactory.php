<?php

namespace Database\Factories;

use App\Models\Aircraft;
use App\Models\MaintenanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenanceRecord> */
class MaintenanceRecordFactory extends Factory
{
    /**
     * Default attributes for a completed A-check with a next due date a year later (not yet due).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['aircraft_id' => Aircraft::factory(), 'kind' => 'a_check', 'title' => 'A-check', 'performed_on' => '2026-06-01', 'next_due_on' => '2027-06-01'];
    }
}
