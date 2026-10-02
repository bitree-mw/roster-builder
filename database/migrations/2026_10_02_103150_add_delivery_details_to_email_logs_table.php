<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roster email delivery details: the address used at the time, who asked for the send, and the failure
 * reason when delivery failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('email')->nullable()->after('roster_period_id');
            $table->foreignId('requested_by')->nullable()->after('email')->constrained('users')->nullOnDelete();
            $table->string('error', 500)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['email', 'error']);
        });
    }
};
