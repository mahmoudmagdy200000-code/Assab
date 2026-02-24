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
        if (Schema::hasTable('waste_damage_reports')) {
            return;
        }

        Schema::create('waste_damage_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('created_by');
            $table->string('assigned_to_type', 20)->default('personal');
            $table->uuid('assigned_to_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('created_by');
            $table->index('assigned_to_id');
            $table->index('status');

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
        Schema::dropIfExists('waste_damage_reports');
    }
};
