<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'partial_confirmation' to the status enum
        DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM('pending', 'confirmed', 'partial', 'partial_confirmation', 'rejected', 'received', 'variance', 'needs_approval') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'partial_confirmation' from the enum (convert existing records to 'partial')
        DB::table('purchase_order_items')
            ->where('status', 'partial_confirmation')
            ->update(['status' => 'partial']);

        DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM('pending', 'confirmed', 'partial', 'rejected', 'received', 'variance', 'needs_approval') DEFAULT 'pending'");
    }
};

