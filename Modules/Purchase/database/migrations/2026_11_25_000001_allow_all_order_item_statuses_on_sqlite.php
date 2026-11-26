<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('purchase_order_items')) {
            return;
        }

        // Earlier ENUM-alter migrations skipped SQLite, leaving the initial 6-value
        // CHECK constraint on purchase_order_items.status and blocking OrderItemStatus::CLOSED.
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('status', 64)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Non-destructive on SQLite
    }
};
