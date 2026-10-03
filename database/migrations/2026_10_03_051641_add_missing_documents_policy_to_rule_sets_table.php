<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The explicit policy for crew whose licence, medical or recurrent expiry date is not on record:
 * "warn" rosters them and shows a warning on the seat; "block" never rosters them automatically.
 * Expired documents always block. Existing rule sets start with "warn" so rosters are generated even
 * while document records are still being entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->enum('missing_documents', ['warn', 'block'])->default('warn')->after('max_days_off_week');
        });
    }

    public function down(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->dropColumn('missing_documents');
        });
    }
};
