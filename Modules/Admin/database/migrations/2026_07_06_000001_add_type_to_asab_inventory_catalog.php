<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_inventory_catalog', function (Blueprint $table) {
            // Discriminates sales items from purchase raw materials so the two
            // brand uploads stay separable after import (client meeting: the
            // materials upload is a distinct dataset for the purchasing module).
            $table->string('type', 16)->default('sales_item')->after('brand_id');

            $table->index(['brand_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('asab_inventory_catalog', function (Blueprint $table) {
            $table->dropIndex(['brand_id', 'type']);
            $table->dropColumn('type');
        });
    }
};
