<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each crew member's duties were when a roster was last published ({crew id: [duty line, …]}), and the
 * crew whose duties changed at the latest publication. When a reopened roster is published again (for example
 * after flights were disabled or edited), crew control can email just the crew whose duties changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->json('published_duties')->nullable()->after('published_by');
            $table->json('changed_crew')->nullable()->after('published_duties');
        });
    }

    public function down(): void
    {
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->dropColumn(['published_duties', 'changed_crew']);
        });
    }
};
