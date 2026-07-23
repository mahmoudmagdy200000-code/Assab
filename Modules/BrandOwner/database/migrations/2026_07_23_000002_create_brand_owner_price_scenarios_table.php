<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved price-change simulation scenarios (Menu Engineering → Pricing Simulator).
 * Each row is one saved simulation result belonging to the brand owner (or branch
 * manager) who ran it. Powers POST price-simulator/simulate + saved-scenarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_owner_price_scenarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_owner_id');
            $table->uuid('branch_id');
            $table->string('branch_name')->nullable();
            $table->uuid('item_id');
            $table->string('item_name');

            // Baseline (as-of simulation time)
            $table->decimal('current_selling_price', 12, 2);
            $table->decimal('production_cost', 12, 2);
            $table->unsignedInteger('current_monthly_sales');
            $table->decimal('expected_growth_percentage', 8, 2);

            // Simulated outcome (persisted so saved-scenarios never recomputes)
            $table->decimal('new_price', 12, 2);
            $table->unsignedInteger('expected_sales');
            $table->decimal('expected_sales_change_percentage', 8, 2);
            $table->decimal('new_unit_profit', 12, 2);
            $table->decimal('new_unit_profit_change_percentage', 8, 2);
            $table->decimal('new_monthly_profit', 14, 2);
            $table->decimal('profit_change', 14, 2);
            $table->decimal('profit_change_percentage', 8, 2);

            $table->timestamps();
            $table->softDeletes();

            $table->index('brand_owner_id');
            $table->index('branch_id');
            $table->index(['brand_owner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_owner_price_scenarios');
    }
};
