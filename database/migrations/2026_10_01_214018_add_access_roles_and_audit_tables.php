<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access roles and crew links on users, the audit log, and roster email delivery logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['scheduler', 'crew_control', 'crew'])->default('crew');
            $table->foreignId('crew_member_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entity', 'entity_id']);
        });
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_member_id')->constrained()->restrictOnDelete();
            $table->foreignId('roster_period_id')->constrained()->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('audit_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crew_member_id');
            $table->dropColumn('role');
        });
    }
};
