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
        if (Schema::hasTable('recurring_orders')) {
            return;
        }

        Schema::create('recurring_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('created_by'); // Branch Manager
            $table->string('order_name');
            $table->string('order_source_type'); // direct_supplier, via_purchasing_officer
            $table->string('status'); // generated, in_progress, pending, paused
            $table->uuidMorphs('sourceable'); // Supplier or BranchManager (purchasing officer)
            $table->string('repeat_frequency'); // weekly, monthly, based_on_inventory
            $table->json('repeat_config')->nullable(); // days, dates, pattern, threshold
            $table->time('scheduling_time_am')->nullable();
            $table->time('scheduling_time_pm')->nullable();
            $table->json('notification_options')->nullable();
            $table->json('smart_settings')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('end_type')->default('repeat'); // repeat, date
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('created_by');
            $table->index('status');
            $table->index('order_source_type');
            $table->index('next_run_at');
            $table->index(['branch_id', 'status']);

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('branch_managers')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_orders');
    }
};
