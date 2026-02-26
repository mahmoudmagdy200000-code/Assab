<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add counted_by morph for team contribution (who recorded each product quantity).
     */
    public function up(): void
    {
        Schema::table('monthly_inventory_products', function (Blueprint $table) {
            $table->uuid('counted_by_id')->nullable()->after('locked_at');
            $table->string('counted_by_type', 64)->nullable()->after('counted_by_id');
            $table->index(['counted_by_id', 'counted_by_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monthly_inventory_products', function (Blueprint $table) {
            $table->dropIndex(['counted_by_id', 'counted_by_type']);
            $table->dropColumn(['counted_by_id', 'counted_by_type']);
        });
    }
};
