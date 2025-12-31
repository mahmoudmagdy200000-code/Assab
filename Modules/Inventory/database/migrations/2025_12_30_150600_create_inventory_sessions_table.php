<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('inventory_sessions')) {
            return;
        }

        Schema::create('inventory_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('session_number')->unique();

            // Branch information
            $table->uuid('branch_id');
            $table->uuid('created_by'); // Branch Manager ID

            // Assignment
            $table->uuid('assigned_to_id')->nullable(); // Cashier ID (nullable for personal)
            $table->enum('assigned_to_type', ['personal', 'staff'])->default('personal');

            // Session details
            $table->date('inventory_date');
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->integer('time_taken')->nullable(); // in seconds

            // Status
            $table->enum('status', ['draft', 'completed'])->default('draft');

            // Notes
            $table->text('notes')->nullable();

            // Timestamps
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('branch_id');
            $table->index('created_by');
            $table->index('assigned_to_id');
            $table->index('status');
            $table->index('session_number');

            // Foreign keys
            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign('created_by')
                ->references('id')
                ->on('branch_managers')
                ->cascadeOnDelete();

            $table->foreign('assigned_to_id')
                ->references('id')
                ->on('cashiers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_sessions');
    }
};

