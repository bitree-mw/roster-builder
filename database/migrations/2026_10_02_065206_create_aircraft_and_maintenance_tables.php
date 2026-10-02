<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual airframes with availability status, and their maintenance records (date- and hour-based due points).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aircraft', function (Blueprint $table) {
            $table->id();
            $table->string('registration', 12)->unique();
            $table->foreignId('aircraft_type_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['available', 'maintenance', 'grounded', 'unavailable'])->default('available');
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->decimal('airframe_hours', 9, 1)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        // restrictOnDelete: an airframe with maintenance history cannot be deleted, only marked unavailable.
        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aircraft_id')->constrained('aircraft')->restrictOnDelete();
            $table->enum('kind', ['line_check', 'a_check', 'c_check', 'inspection', 'component', 'service', 'other']);
            $table->string('title', 150);
            $table->date('performed_on');
            $table->decimal('airframe_hours_at', 9, 1)->nullable();
            $table->date('next_due_on')->nullable();
            $table->decimal('next_due_hours', 9, 1)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['aircraft_id', 'kind', 'performed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_records');
        Schema::dropIfExists('aircraft');
    }
};
