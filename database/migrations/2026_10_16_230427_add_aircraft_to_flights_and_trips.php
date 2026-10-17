<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes on flight plans and trips:
 *  - aircraft_types.name: the type's full name, e.g. "Boeing 737-800" for B737, shown with registrations;
 *  - flight_aircraft: the airframes that may fly a flight plan, in order of preference (any type);
 *  - trips.aircraft_id / aircraft_source: the airframe flying a dated trip, chosen by the roster build (auto)
 *    or set by crew control (manual, kept on rebuild). A trip follows its plane's type for crew requirements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aircraft_types', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('code');
        });
        Schema::create('flight_aircraft', function (Blueprint $table) {
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(1);
            $table->primary(['flight_id', 'aircraft_id']);
        });
        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('aircraft_id')->nullable()->after('flight_id')->constrained('aircraft')->nullOnDelete();
            $table->enum('aircraft_source', ['auto', 'manual'])->default('auto')->after('aircraft_id');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aircraft_id');
            $table->dropColumn('aircraft_source');
        });
        Schema::dropIfExists('flight_aircraft');
        Schema::table('aircraft_types', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
