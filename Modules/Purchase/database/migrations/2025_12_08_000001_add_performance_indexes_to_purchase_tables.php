<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds performance indexes for common query patterns
     */
    public function up(): void
    {
        // Indexes for purchase_order_items table
        if (Schema::hasTable('purchase_order_items')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                // Composite index for item_id + created_at (used in price trends)
                if (!$this->indexExists('purchase_order_items', 'purchase_order_items_item_id_created_at_index')) {
                    $table->index(['item_id', 'created_at'], 'purchase_order_items_item_id_created_at_index');
                }
                
                // Composite index for purchase_order_id + item_id
                if (!$this->indexExists('purchase_order_items', 'purchase_order_items_order_id_item_id_index')) {
                    $table->index(['purchase_order_id', 'item_id'], 'purchase_order_items_order_id_item_id_index');
                }
            });
        }

        // Indexes for purchase_orders table
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                // Composite index for order_type + status + created_at (used in price comparison)
                if (!$this->indexExists('purchase_orders', 'purchase_orders_type_status_created_at_index')) {
                    $table->index(['order_type', 'status', 'created_at'], 'purchase_orders_type_status_created_at_index');
                }
                
                // Composite index for branch_id + order_type + created_at
                if (!$this->indexExists('purchase_orders', 'purchase_orders_branch_type_created_at_index')) {
                    $table->index(['branch_id', 'order_type', 'created_at'], 'purchase_orders_branch_type_created_at_index');
                }
                
                // Composite index for from_branch_id + order_type (used in internal transfers)
                if (!$this->indexExists('purchase_orders', 'purchase_orders_from_branch_type_index')) {
                    $table->index(['from_branch_id', 'order_type'], 'purchase_orders_from_branch_type_index');
                }
                
                // Composite index for supplier_id + order_type + created_at
                if (!$this->indexExists('purchase_orders', 'purchase_orders_supplier_type_created_at_index')) {
                    $table->index(['supplier_id', 'order_type', 'created_at'], 'purchase_orders_supplier_type_created_at_index');
                }
            });
        }

        // Indexes for branch_inventory table
        if (Schema::hasTable('branch_inventory')) {
            Schema::table('branch_inventory', function (Blueprint $table) {
                // Composite index for item_id + branch_id (used in getBranchesWithStock)
                if (!$this->indexExists('branch_inventory', 'branch_inventory_item_branch_index')) {
                    $table->index(['item_id', 'branch_id'], 'branch_inventory_item_branch_index');
                }
                
                // Index for available_quantity calculations
                if (!$this->indexExists('branch_inventory', 'branch_inventory_available_quantity_index')) {
                    $table->index('available_quantity', 'branch_inventory_available_quantity_index');
                }
            });
        }

        // Indexes for supplier_items table
        if (Schema::hasTable('supplier_items')) {
            Schema::table('supplier_items', function (Blueprint $table) {
                // Composite index for item_id + is_available (used in getDirectSupplierItems)
                if (!$this->indexExists('supplier_items', 'supplier_items_item_available_index')) {
                    $table->index(['item_id', 'is_available'], 'supplier_items_item_available_index');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('purchase_order_items')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->dropIndex('purchase_order_items_item_id_created_at_index');
                $table->dropIndex('purchase_order_items_order_id_item_id_index');
            });
        }

        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropIndex('purchase_orders_type_status_created_at_index');
                $table->dropIndex('purchase_orders_branch_type_created_at_index');
                $table->dropIndex('purchase_orders_from_branch_type_index');
                $table->dropIndex('purchase_orders_supplier_type_created_at_index');
            });
        }

        if (Schema::hasTable('branch_inventory')) {
            Schema::table('branch_inventory', function (Blueprint $table) {
                $table->dropIndex('branch_inventory_item_branch_index');
                $table->dropIndex('branch_inventory_available_quantity_index');
            });
        }

        if (Schema::hasTable('supplier_items')) {
            Schema::table('supplier_items', function (Blueprint $table) {
                $table->dropIndex('supplier_items_item_available_index');
            });
        }
    }

    /**
     * Check if index exists
     */
    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();
        
        $result = $connection->select(
            "SELECT COUNT(*) as count 
             FROM information_schema.statistics 
             WHERE table_schema = ? 
             AND table_name = ? 
             AND index_name = ?",
            [$databaseName, $table, $index]
        );
        
        return $result[0]->count > 0;
    }
};

