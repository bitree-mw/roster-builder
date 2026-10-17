<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captain sections: a captain seat may cover only part of a trip, as a range of its legs (numbered from 1 in
 * schedule order). Null on both means the whole trip, which stays the rule for first officers and cabin crew.
 * A split captain seat becomes several CPT seats (seat_number 1, 2, …) whose ranges cover the trip in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->unsignedSmallInteger('from_leg')->nullable()->after('seat_number');
            $table->unsignedSmallInteger('to_leg')->nullable()->after('from_leg');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn(['from_leg', 'to_leg']);
        });
    }
};
