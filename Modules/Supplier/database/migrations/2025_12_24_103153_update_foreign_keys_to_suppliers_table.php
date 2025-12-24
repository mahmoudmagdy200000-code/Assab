<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Update foreign keys in Purchase module to reference new suppliers table
     */
    public function up(): void
    {
        // Update purchase_orders table foreign key
        if (Schema::hasTable('purchase_orders') && Schema::hasColumn('purchase_orders', 'supplier_id')) {
            // Drop old foreign key if exists
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
            });

            // Add new foreign key to suppliers table
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->foreign('supplier_id')
                    ->references('id')
                    ->on('suppliers')
                    ->nullOnDelete();
            });
        }

        // Update supplier_items table foreign key
        if (Schema::hasTable('supplier_items') && Schema::hasColumn('supplier_items', 'supplier_id')) {
            Schema::table('supplier_items', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
            });

            Schema::table('supplier_items', function (Blueprint $table) {
                $table->foreign('supplier_id')
                    ->references('id')
                    ->on('suppliers')
                    ->cascadeOnDelete();
            });
        }

        // Update purchase_invoices table foreign key
        if (Schema::hasTable('purchase_invoices') && Schema::hasColumn('purchase_invoices', 'supplier_id')) {
            Schema::table('purchase_invoices', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
            });

            Schema::table('purchase_invoices', function (Blueprint $table) {
                $table->foreign('supplier_id')
                    ->references('id')
                    ->on('suppliers')
                    ->nullOnDelete();
            });
        }

        // Update return_orders table foreign key
        if (Schema::hasTable('return_orders') && Schema::hasColumn('return_orders', 'supplier_id')) {
            Schema::table('return_orders', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
            });

            Schema::table('return_orders', function (Blueprint $table) {
                $table->foreign('supplier_id')
                    ->references('id')
                    ->on('suppliers')
                    ->nullOnDelete();
            });
        }

        // Update expense module suppliers foreign key if needed
        // Note: Expense module may use the same suppliers table, so this might not be needed
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert foreign keys back to purchase_suppliers table
        // Note: This assumes purchase_suppliers table still exists
        // In production, this should be handled carefully

        if (Schema::hasTable('purchase_suppliers')) {
            if (Schema::hasTable('purchase_orders') && Schema::hasColumn('purchase_orders', 'supplier_id')) {
                Schema::table('purchase_orders', function (Blueprint $table) {
                    $table->dropForeign(['supplier_id']);
                });

                Schema::table('purchase_orders', function (Blueprint $table) {
                    $table->foreign('supplier_id')
                        ->references('id')
                        ->on('purchase_suppliers')
                        ->nullOnDelete();
                });
            }
        }
    }
};
