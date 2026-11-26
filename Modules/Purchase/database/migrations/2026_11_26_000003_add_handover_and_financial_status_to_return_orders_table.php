<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('return_orders')) {
            return;
        }

        Schema::table('return_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('return_orders', 'handed_over_at')) {
                $table->timestamp('handed_over_at')->nullable()->after('closed_at');
            }
            if (! Schema::hasColumn('return_orders', 'handed_over_by')) {
                $table->uuid('handed_over_by')->nullable()->after('handed_over_at');
            }
            if (! Schema::hasColumn('return_orders', 'financial_status')) {
                $table->string('financial_status', 64)->default('pending_review')->after('handed_over_by');
            }
            if (! Schema::hasColumn('return_orders', 'reviewed_by_accountant_id')) {
                $table->uuid('reviewed_by_accountant_id')->nullable()->after('financial_status');
            }
            if (! Schema::hasColumn('return_orders', 'reviewed_by_accountant_at')) {
                $table->timestamp('reviewed_by_accountant_at')->nullable()->after('reviewed_by_accountant_id');
            }
            if (! Schema::hasColumn('return_orders', 'approved_by_head_id')) {
                $table->uuid('approved_by_head_id')->nullable()->after('reviewed_by_accountant_at');
            }
            if (! Schema::hasColumn('return_orders', 'approved_by_head_at')) {
                $table->timestamp('approved_by_head_at')->nullable()->after('approved_by_head_id');
            }
            if (! Schema::hasColumn('return_orders', 'financial_notes')) {
                $table->text('financial_notes')->nullable()->after('approved_by_head_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('return_orders')) {
            return;
        }

        Schema::table('return_orders', function (Blueprint $table) {
            $drop = array_values(array_filter(
                [
                    'handed_over_at',
                    'handed_over_by',
                    'financial_status',
                    'reviewed_by_accountant_id',
                    'reviewed_by_accountant_at',
                    'approved_by_head_id',
                    'approved_by_head_at',
                    'financial_notes',
                ],
                fn (string $col) => Schema::hasColumn('return_orders', $col)
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
