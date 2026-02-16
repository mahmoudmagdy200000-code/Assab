<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('sales_quantity', 12, 3)->nullable()->after('quantity_inventory');
            $table->decimal('recorded_waste', 12, 3)->nullable()->after('sales_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['sales_quantity', 'recorded_waste']);
        });
    }
};
