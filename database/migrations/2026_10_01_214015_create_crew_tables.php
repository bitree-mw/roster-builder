<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->enum('rank', ['CPT', 'FO', 'CC']);
            $table->string('base_airport', 4);
            $table->foreign('base_airport')->references('code')->on('airports')->restrictOnDelete();
            $table->boolean('all_aircraft')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'rank', 'base_airport']);
        });
        Schema::create('crew_ratings', function (Blueprint $table) {
            $table->foreignId('crew_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('aircraft_type_id')->constrained()->restrictOnDelete();
            $table->primary(['crew_member_id', 'aircraft_type_id']);
        });
        Schema::create('crew_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_member_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['licence', 'medical', 'recurrent']);
            $table->date('expires_on');
            $table->timestamps();
            $table->unique(['crew_member_id', 'kind']);
        });
        Schema::create('crew_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_member_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['leave', 'sim', 'standby', 'day_off']);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamps();
            $table->unique(['crew_member_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['crew_activities', 'crew_documents', 'crew_ratings', 'crew_members'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
