<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('goods_receipt_items')) {
            return;
        }

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            if (! Schema::hasColumn('goods_receipt_items', 'quantity_available_posted')) {
                $table->decimal('quantity_available_posted', 12, 3)->default(0)->after('quantity_received');
            }
            if (! Schema::hasColumn('goods_receipt_items', 'quantity_excess_reserved')) {
                $table->decimal('quantity_excess_reserved', 12, 3)->default(0)->after('quantity_available_posted');
            }
            if (! Schema::hasColumn('goods_receipt_items', 'excess_status')) {
                $table->string('excess_status', 40)->nullable()->after('quantity_excess_reserved');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('goods_receipt_items')) {
            return;
        }

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            foreach (['excess_status', 'quantity_excess_reserved', 'quantity_available_posted'] as $col) {
                if (Schema::hasColumn('goods_receipt_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
