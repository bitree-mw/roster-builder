<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Airports (keyed by IATA code, with base flag and UTC offset) and aircraft types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table) {
            $table->string('code', 4)->primary();
            $table->string('name');
            $table->smallInteger('utc_offset_minutes')->default(120);
            $table->boolean('is_base')->default(false);
            $table->timestamps();
        });
        // palette stores a semantic token name (forest, gold, ...), never a colour value.
        Schema::create('aircraft_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->unsignedTinyInteger('cabin_crew_required');
            $table->string('palette', 20)->default('forest');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_types');
        Schema::dropIfExists('airports');
    }
};
