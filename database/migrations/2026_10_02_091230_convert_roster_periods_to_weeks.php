<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rosters are made weekly (Monday to Sunday at base) instead of monthly. A period is identified by its
 * Monday (starts_on, unique) and records when and by whom it was last built by the roster generator.
 * Existing monthly drafts move to the week that contains the first day of their month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('id');
            $table->date('ends_on')->nullable()->after('starts_on');
            $table->timestamp('built_at')->nullable()->after('rules_snapshot');
            $table->foreignId('built_by')->nullable()->after('built_at')->constrained('users')->nullOnDelete();
        });
        // Months are at least four weeks apart, so every converted period lands on a different Monday.
        foreach (DB::table('roster_periods')->get(['id', 'month']) as $period) {
            $monday = CarbonImmutable::parse($period->month, 'UTC')->startOfWeek(CarbonImmutable::MONDAY);
            DB::table('roster_periods')->where('id', $period->id)->update(['starts_on' => $monday->format('Y-m-d'), 'ends_on' => $monday->addDays(6)->format('Y-m-d')]);
        }
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->dropUnique(['month']);
            $table->dropColumn('month');
        });
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->date('starts_on')->nullable(false)->change();
            $table->date('ends_on')->nullable(false)->change();
            $table->unique('starts_on');
        });
    }

    /**
     * Back to monthly periods. Only one week per month can survive (the earliest); later weeks of the same
     * month are deleted with their trips, so roll back on disposable databases only.
     */
    public function down(): void
    {
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->date('month')->nullable()->after('id');
        });
        $kept = [];
        foreach (DB::table('roster_periods')->orderBy('starts_on')->get(['id', 'starts_on']) as $period) {
            $month = CarbonImmutable::parse($period->starts_on, 'UTC')->startOfMonth()->format('Y-m-d');
            if (isset($kept[$month])) {
                DB::table('roster_periods')->where('id', $period->id)->delete();

                continue;
            }
            $kept[$month] = true;
            DB::table('roster_periods')->where('id', $period->id)->update(['month' => $month]);
        }
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->dropUnique(['starts_on']);
            $table->dropConstrainedForeignId('built_by');
            $table->dropColumn(['starts_on', 'ends_on', 'built_at']);
        });
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->date('month')->nullable(false)->change();
            $table->unique('month');
        });
    }
};
