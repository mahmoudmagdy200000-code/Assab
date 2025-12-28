<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, modify the status enum to include 'needs_approval'
        DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM('pending', 'confirmed', 'partial', 'rejected', 'received', 'variance', 'needs_approval') DEFAULT 'pending'");

        Schema::table('purchase_order_items', function (Blueprint $table) {
            // Add approval_type enum field
            $table->enum('approval_type', ['partial', 'time_change', 'alternative'])->nullable()->after('status');
            
            // Add approval_data JSON field for storing request details
            $table->json('approval_data')->nullable()->after('approval_type');
        });

        // Migrate existing data: convert 'partial' status to 'needs_approval' with approval_type
        $partialItems = DB::table('purchase_order_items')
            ->where('status', 'partial')
            ->get();

        foreach ($partialItems as $item) {
            $approvalData = json_encode([
                'original_quantity' => $item->quantity_ordered,
                'requested_quantity' => $item->quantity_confirmed ?? $item->quantity_ordered,
            ]);

            DB::table('purchase_order_items')
                ->where('id', $item->id)
                ->update([
                    'status' => 'needs_approval',
                    'approval_type' => 'partial',
                    'approval_data' => $approvalData,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert back: needs_approval with approval_type='partial' → partial
        DB::table('purchase_order_items')
            ->where('status', 'needs_approval')
            ->where('approval_type', 'partial')
            ->update(['status' => 'partial']);

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['approval_type', 'approval_data']);
        });
    }
};
