<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contracted working hours per week for each crew member. The roster generator never plans more duty than
 * this in a Monday–Sunday week and prefers whoever has used the smallest share of it. Null means no
 * personal limit: only the duty rules' rolling seven-day limit applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_members', function (Blueprint $table) {
            $table->unsignedTinyInteger('weekly_hours')->nullable()->after('all_aircraft');
        });
    }

    public function down(): void
    {
        Schema::table('crew_members', function (Blueprint $table) {
            $table->dropColumn('weekly_hours');
        });
    }
};
