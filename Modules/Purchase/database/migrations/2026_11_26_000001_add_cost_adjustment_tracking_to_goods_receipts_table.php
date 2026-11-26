<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('goods_receipts')) {
            return;
        }

        Schema::table('goods_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('goods_receipts', 'cost_adjustment_status')) {
                $table->string('cost_adjustment_status', 64)->nullable()->after('document_type');
            }
            if (! Schema::hasColumn('goods_receipts', 'pending_cost_delta_halalas')) {
                $table->bigInteger('pending_cost_delta_halalas')->nullable()->after('cost_adjustment_status');
            }
            if (! Schema::hasColumn('goods_receipts', 'cost_adjustment_meta')) {
                $table->json('cost_adjustment_meta')->nullable()->after('pending_cost_delta_halalas');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('goods_receipts')) {
            return;
        }

        Schema::table('goods_receipts', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['cost_adjustment_status', 'pending_cost_delta_halalas', 'cost_adjustment_meta'],
                fn (string $col) => Schema::hasColumn('goods_receipts', $col)
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
