<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the "admin" role: administrators manage every account (including other administrators) and have all
 * scheduler permissions. Pilots and cabin crew keep the "crew" role, linked to their crew profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'scheduler', 'crew_control', 'crew'])->default('crew')->change();
        });
    }

    /**
     * Administrators become schedulers again (the closest remaining role) before the value is removed.
     */
    public function down(): void
    {
        DB::table('users')->where('role', 'admin')->update(['role' => 'scheduler']);
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['scheduler', 'crew_control', 'crew'])->default('crew')->change();
        });
    }
};
