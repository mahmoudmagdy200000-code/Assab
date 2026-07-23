<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved item profitability tests (Menu Engineering → Item Test screen).
 * Each row is one submitted-and-saved test result belonging to the brand owner
 * (or branch manager) who ran it. Powers POST item-test/submit + saved-tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_owner_item_tests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_owner_id');
            $table->uuid('branch_id');
            $table->string('branch_name')->nullable();
            $table->string('item_name');

            // Inputs
            $table->decimal('expected_selling_price', 12, 2);
            $table->decimal('production_cost', 12, 2);
            $table->unsignedInteger('expected_sales');
            $table->decimal('expected_growth', 8, 2);

            // Calculated result (persisted so saved-tests never recomputes)
            $table->decimal('profit_margin', 8, 2)->default(0);
            $table->decimal('expected_monthly_profit', 14, 2)->default(0);
            $table->string('expected_classification')->nullable();
            $table->text('menu_impact')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('brand_owner_id');
            $table->index('branch_id');
            $table->index(['brand_owner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_owner_item_tests');
    }
};
