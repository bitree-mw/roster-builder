<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    /**
     * Default attributes for an audit row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'action' => 'created', 'entity' => 'aircraft_types', 'entity_id' => 1, 'after' => ['code' => 'Q400']];
    }
}
