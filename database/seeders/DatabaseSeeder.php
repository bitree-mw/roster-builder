<?php

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\RuleSet;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['LLW' => 'Lilongwe', 'BLZ' => 'Blantyre', 'LUN' => 'Lusaka', 'HRE' => 'Harare', 'JNB' => 'Johannesburg', 'DAR' => 'Dar es Salaam', 'NBO' => 'Nairobi', 'ADD' => 'Addis Ababa'] as $code => $name) {
            Airport::firstOrCreate(['code' => $code], ['name' => $name, 'utc_offset_minutes' => in_array($code, ['DAR', 'NBO', 'ADD']) ? 180 : 120, 'is_base' => in_array($code, ['LLW', 'BLZ'])]);
        }
        RuleSet::firstOrCreate(['id' => 1], ['name' => 'Standard']);
    }
}
