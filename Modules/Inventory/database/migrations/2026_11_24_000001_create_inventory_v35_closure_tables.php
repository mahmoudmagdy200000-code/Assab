<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_stock_movements')) {
            Schema::create('inventory_stock_movements', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('idempotency_key')->unique();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('branch_id')->index();
                $table->uuid('item_id')->index();
                $table->string('movement_type', 48)->index();
                // positive = in, negative = out
                $table->decimal('qty_delta', 14, 3);
                $table->bigInteger('unit_cost_halalas')->default(0);
                $table->bigInteger('total_cost_halalas')->default(0);
                $table->string('source_type', 64)->nullable();
                $table->uuid('source_id')->nullable()->index();
                $table->uuid('operation_id')->nullable()->index();
                $table->integer('revision')->default(1);
                $table->string('cost_source', 48)->nullable();
                $table->integer('cost_revision')->nullable();
                $table->timestamp('posted_at')->index();
                $table->uuid('created_by_id')->nullable();
                $table->text('notes')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['branch_id', 'item_id', 'posted_at'], 'inv_mov_branch_item_posted_idx');
            });
        }

        if (! Schema::hasTable('inventory_monthly_costs')) {
            Schema::create('inventory_monthly_costs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->index();
                $table->uuid('item_id')->index();
                $table->string('year_month', 7)->index(); // YYYY-MM
                $table->decimal('opening_qty', 14, 3)->default(0);
                $table->bigInteger('opening_value_halalas')->default(0);
                $table->decimal('purchases_qty', 14, 3)->default(0);
                $table->bigInteger('purchases_value_halalas')->default(0);
                $table->decimal('transfers_in_qty', 14, 3)->default(0);
                $table->bigInteger('transfers_in_value_halalas')->default(0);
                $table->bigInteger('unit_cost_halalas')->default(0);
                $table->string('status', 24)->default('PROVISIONAL'); // PROVISIONAL | APPROVED
                $table->integer('revision')->default(1);
                $table->uuid('approved_by_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'branch_id', 'item_id', 'year_month'], 'inv_monthly_cost_unique');
            });
        }

        if (! Schema::hasTable('consumption_import_batches')) {
            Schema::create('consumption_import_batches', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->string('source_system', 64)->default('external_pos');
                $table->string('file_name')->nullable();
                $table->string('file_hash', 64)->index();
                $table->string('status', 24)->default('PREVIEW'); // PREVIEW | CONFIRMED | REJECTED
                $table->json('validation_errors')->nullable();
                $table->json('preview_summary')->nullable();
                $table->json('parsed_payload')->nullable();
                $table->uuid('uploaded_by_id')->nullable();
                $table->uuid('confirmed_by_id')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('consumption_daily_coverages')) {
            Schema::create('consumption_daily_coverages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->string('source_system', 64)->default('external_pos');
                $table->uuid('branch_id')->index();
                $table->string('branch_code', 64);
                $table->date('business_date')->index();
                $table->integer('revision')->default(1);
                $table->string('day_status', 16); // COMPLETE | PARTIAL
                $table->boolean('includes_waste')->default(false);
                $table->string('source_ref', 128)->nullable();
                $table->string('content_hash', 64);
                $table->string('status', 24)->default('CONFIRMED'); // CONFIRMED | SUPERSEDED
                $table->uuid('batch_id')->index();
                $table->uuid('confirmed_by_id')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'source_system', 'branch_id', 'business_date', 'revision'], 'cons_cov_rev_unique');
                $table->index(['branch_id', 'business_date', 'status'], 'cons_cov_active_idx');
            });
        }

        if (! Schema::hasTable('consumption_daily_lines')) {
            Schema::create('consumption_daily_lines', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('coverage_id')->index();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->index();
                $table->date('business_date')->index();
                $table->uuid('item_id')->index();
                $table->string('item_code', 64);
                $table->string('unit_code', 24);
                $table->decimal('consumed_qty', 14, 3)->default(0);
                $table->decimal('included_waste_qty', 14, 3)->default(0);
                $table->decimal('normal_consumed_qty', 14, 3)->default(0);
                $table->timestamps();

                $table->unique(['coverage_id', 'item_id'], 'cons_line_cov_item_unique');
            });
        }

        if (! Schema::hasTable('internal_transfer_variances')) {
            Schema::create('internal_transfer_variances', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('purchase_order_id')->index();
                $table->uuid('purchase_order_item_id')->index();
                $table->uuid('goods_receipt_id')->nullable()->index();
                $table->uuid('from_branch_id')->index();
                $table->uuid('to_branch_id')->index();
                $table->uuid('item_id')->index();
                $table->decimal('dispatched_qty', 14, 3);
                $table->decimal('received_qty', 14, 3);
                $table->decimal('in_transit_qty', 14, 3);
                $table->decimal('variance_qty', 14, 3)->default(0);
                $table->bigInteger('sender_unit_cost_halalas')->default(0);
                $table->string('resolution', 32)->nullable(); // late_receipt | returned_to_sender | standing_variance
                $table->string('status', 24)->default('IN_TRANSIT'); // IN_TRANSIT | CLOSED
                $table->text('reason')->nullable();
                $table->string('evidence_path')->nullable();
                $table->string('idempotency_key')->nullable()->unique();
                $table->uuid('closed_by_id')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_transfer_variances');
        Schema::dropIfExists('consumption_daily_lines');
        Schema::dropIfExists('consumption_daily_coverages');
        Schema::dropIfExists('consumption_import_batches');
        Schema::dropIfExists('inventory_monthly_costs');
        Schema::dropIfExists('inventory_stock_movements');
    }
};
