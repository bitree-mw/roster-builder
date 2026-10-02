<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flight patterns, their operating weekdays (Monday = 0) and ordered legs with base-local times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('aircraft_type_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('flight_days', function (Blueprint $table) {
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->primary(['flight_id', 'weekday']);
        });
        // Legs are ordered by trip day (1-4) and sequence within the day.
        Schema::create('flight_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('trip_day');
            $table->unsignedTinyInteger('sequence');
            $table->string('from_airport', 4);
            $table->string('to_airport', 4);
            $table->foreign('from_airport')->references('code')->on('airports')->restrictOnDelete();
            $table->foreign('to_airport')->references('code')->on('airports')->restrictOnDelete();
            $table->time('departs_local');
            $table->time('arrives_local');
            $table->timestamps();
            $table->unique(['flight_id', 'trip_day', 'sequence']);
        });
    }

    public function down(): void
    {
        foreach (['flight_legs', 'flight_days', 'flights'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
