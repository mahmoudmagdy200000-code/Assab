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
        // For MySQL, we need to modify the enum values
        // First, change the column to string temporarily
        DB::statement("ALTER TABLE purchase_orders MODIFY ready_time VARCHAR(50) NULL");

        // Then update existing values to new format
        // Map old values to new values
        DB::statement("UPDATE purchase_orders SET ready_time = 'non' WHERE ready_time = '3_minutes' OR ready_time IS NULL");
        // Note: '30_min' is new, no direct mapping from old values
        DB::statement("UPDATE purchase_orders SET ready_time = '1_hour' WHERE ready_time = '1_hour'");
        DB::statement("UPDATE purchase_orders SET ready_time = '2_hours' WHERE ready_time = '2_hours'");
        DB::statement("UPDATE purchase_orders SET ready_time = '3_hours_or_more' WHERE ready_time = '3_hours' OR ready_time = 'more_than_3_hours'");

        // Finally, change back to enum with new values
        DB::statement("ALTER TABLE purchase_orders MODIFY ready_time ENUM('non', '30_min', '1_hour', '2_hours', '3_hours_or_more') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Change to string first
        DB::statement("ALTER TABLE purchase_orders MODIFY ready_time VARCHAR(50) NULL");

        // Revert to old values
        DB::statement("UPDATE purchase_orders SET ready_time = '3_minutes' WHERE ready_time = 'non'");
        DB::statement("UPDATE purchase_orders SET ready_time = '1_hour' WHERE ready_time = '30_min'");
        DB::statement("UPDATE purchase_orders SET ready_time = '2_hours' WHERE ready_time = '1_hour'");
        DB::statement("UPDATE purchase_orders SET ready_time = '3_hours' WHERE ready_time = '2_hours'");
        DB::statement("UPDATE purchase_orders SET ready_time = 'more_than_3_hours' WHERE ready_time = '3_hours_or_more'");

        // Change back to old enum
        DB::statement("ALTER TABLE purchase_orders MODIFY ready_time ENUM('3_minutes', '1_hour', '2_hours', '3_hours', 'more_than_3_hours') NULL");
    }
};

