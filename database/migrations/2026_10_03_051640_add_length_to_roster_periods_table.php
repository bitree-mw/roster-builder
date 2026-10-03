<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rosters can cover one week, two weeks (a fortnight from a Monday) or a calendar month. Existing periods
 * are weeks. starts_on stays unique; RosterPeriodService refuses periods that overlap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->enum('length', ['week', 'fortnight', 'month'])->default('week')->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->dropColumn('length');
        });
    }
};
