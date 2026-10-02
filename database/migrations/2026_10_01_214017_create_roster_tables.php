<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Standard');
            $table->unsignedSmallInteger('report_before_min')->default(60);
            $table->unsignedSmallInteger('release_after_min')->default(30);
            $table->decimal('max_duty_day_h', 5, 2)->default(13);
            $table->decimal('min_rest_h', 5, 2)->default(12);
            $table->decimal('max_duty_7d_h', 5, 2)->default(60);
            $table->decimal('max_block_month_h', 6, 2)->default(100);
            $table->unsignedTinyInteger('max_consecutive_days')->default(5);
            $table->unsignedTinyInteger('min_days_off_month')->default(8);
            $table->unsignedTinyInteger('max_days_off_week')->default(4);
            $table->smallInteger('utc_offset_minutes')->default(120);
            $table->timestamps();
        });
        Schema::create('roster_periods', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->json('rules_snapshot');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flight_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->json('schedule_snapshot');
            $table->timestamps();
            $table->unique(['roster_period_id', 'flight_id', 'start_date']);
        });
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->enum('rank', ['CPT', 'FO', 'CC']);
            $table->unsignedTinyInteger('seat_number')->default(1);
            $table->foreignId('crew_member_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('source', ['auto', 'manual'])->default('auto');
            $table->json('flag_reasons')->nullable();
            $table->json('decision_log')->nullable();
            $table->timestamps();
            $table->unique(['trip_id', 'rank', 'seat_number']);
            $table->unique(['trip_id', 'crew_member_id']);
        });
        Schema::create('exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crew_member_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['trip_id', 'crew_member_id']);
        });
    }

    public function down(): void
    {
        foreach (['exclusions', 'assignments', 'trips', 'roster_periods', 'rule_sets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
