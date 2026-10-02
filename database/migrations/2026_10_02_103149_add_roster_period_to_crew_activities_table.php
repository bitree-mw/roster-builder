<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks standby days planned by the roster generator (to fill workload gaps) with the week that created
 * them, so a rebuild can replace them. Activities entered by people keep a null roster_period_id and are
 * never touched by the generator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_activities', function (Blueprint $table) {
            $table->foreignId('roster_period_id')->nullable()->after('note')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crew_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('roster_period_id');
        });
    }
};
