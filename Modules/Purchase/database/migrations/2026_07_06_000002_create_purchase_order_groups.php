<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A consolidation batch: confirmed purchase orders for one supplier,
        // sent together from the purchasing-manager dashboard.
        Schema::create('purchase_order_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('group_number', 32)->unique();
            $table->uuid('supplier_id')->index();
            $table->uuid('created_by_asab_user_id')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->uuid('group_id')->nullable()->index()->after('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('group_id');
        });
        Schema::dropIfExists('purchase_order_groups');
    }
};
