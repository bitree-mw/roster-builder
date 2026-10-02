<?php

namespace Database\Factories;

use App\Models\CrewDocument;
use App\Models\CrewMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CrewDocument> */
class CrewDocumentFactory extends Factory
{
    /**
     * Default attributes for a licence that does not expire for years (state "valid").
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['crew_member_id' => CrewMember::factory(), 'kind' => 'licence', 'expires_on' => '2030-12-31'];
    }
}
