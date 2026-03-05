<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Renames status enum value from pending_your_action to pending_your_confirmation.
     */
    public function up(): void
    {
        // MySQL: add new enum value, migrate data, then use only the new value
        DB::statement("ALTER TABLE inventory_sessions MODIFY COLUMN status ENUM('draft', 'pending', 'approved', 'rejected', 'pending_your_action', 'pending_your_confirmation', 'completed') DEFAULT 'draft'");
        DB::table('inventory_sessions')->where('status', 'pending_your_action')->update(['status' => 'pending_your_confirmation']);
        DB::statement("ALTER TABLE inventory_sessions MODIFY COLUMN status ENUM('draft', 'pending', 'approved', 'rejected', 'pending_your_confirmation', 'completed') DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE inventory_sessions MODIFY COLUMN status ENUM('draft', 'pending', 'approved', 'rejected', 'pending_your_action', 'pending_your_confirmation', 'completed') DEFAULT 'draft'");
        DB::table('inventory_sessions')->where('status', 'pending_your_confirmation')->update(['status' => 'pending_your_action']);
        DB::statement("ALTER TABLE inventory_sessions MODIFY COLUMN status ENUM('draft', 'pending', 'approved', 'rejected', 'pending_your_action', 'completed') DEFAULT 'draft'");
    }
};
