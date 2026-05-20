<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Migrates existing branch_item data to new items + branch_item pivot structure
     * This preserves all existing data while restructuring it
     */
    public function up(): void
    {
        // Only run if old backup table exists
        if (! Schema::hasTable('branch_item_old_backup')) {
            return; // No data to migrate
        }

        // Step 1: Create items from unique branch_item records (by name + code)
        // Group by item_name and item_code to create unique items
        $uniqueItems = DB::table('branch_item_old_backup')
            ->select('item_name', 'item_code', 'item_unit', 'item_logo', 'category', 'subcategory')
            ->distinct()
            ->get();

        $itemMap = []; // Map (name|code) => item_id

        foreach ($uniqueItems as $itemData) {
            // Check if item already exists
            $existingItem = DB::table('items')
                ->where('name', $itemData->item_name)
                ->where(function ($query) use ($itemData) {
                    if ($itemData->item_code) {
                        $query->where('code', $itemData->item_code);
                    } else {
                        $query->whereNull('code');
                    }
                })
                ->first();

            if ($existingItem) {
                $itemId = $existingItem->id;
            } else {
                // Create new item
                $itemId = (string) \Illuminate\Support\Str::uuid();
                DB::table('items')->insert([
                    'id' => $itemId,
                    'name' => $itemData->item_name,
                    'code' => $itemData->item_code,
                    'unit' => $itemData->item_unit,
                    'logo' => is_string($itemData->item_logo) ? json_encode([$itemData->item_logo]) : $itemData->item_logo,
                    'category' => $itemData->category,
                    'subcategory' => $itemData->subcategory,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $key = ($itemData->item_name ?? '').'|'.($itemData->item_code ?? '');
            $itemMap[$key] = $itemId;
        }

        // Step 2: Create branch_item pivot records for all branches that have this item
        $branchItems = DB::table('branch_item_old_backup')->get();

        foreach ($branchItems as $branchItem) {
            $key = ($branchItem->item_name ?? '').'|'.($branchItem->item_code ?? '');
            $itemId = $itemMap[$key] ?? null;

            if ($itemId) {
                // Check if pivot record already exists
                $exists = DB::table('branch_item')
                    ->where('branch_id', $branchItem->branch_id)
                    ->where('item_id', $itemId)
                    ->exists();

                if (! $exists) {
                    DB::table('branch_item')->insert([
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'branch_id' => $branchItem->branch_id,
                        'item_id' => $itemId,
                        'price' => $branchItem->item_price ?? 0,
                        'quantity' => $branchItem->item_quantity ?? 0,
                        'created_at' => $branchItem->created_at ?? now(),
                        'updated_at' => $branchItem->updated_at ?? now(),
                    ]);
                }
            }
        }

        // Step 3: Update branch_inventory.item_id to reference items.id instead of branch_item_old_backup.id
        // Match by finding the item through branch_item_old_backup
        // Use raw query for better performance with large datasets
        $inventories = DB::table('branch_inventory')
            ->join('branch_item_old_backup', 'branch_inventory.item_id', '=', 'branch_item_old_backup.id')
            ->select('branch_inventory.id as inventory_id', 'branch_item_old_backup.item_name', 'branch_item_old_backup.item_code')
            ->get();

        foreach ($inventories as $inv) {
            $key = ($inv->item_name ?? '').'|'.($inv->item_code ?? '');
            $itemId = $itemMap[$key] ?? null;

            if ($itemId) {
                DB::table('branch_inventory')
                    ->where('id', $inv->inventory_id)
                    ->update(['item_id' => $itemId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is complex to reverse
        // In production, you should backup before running migrations
    }
};
