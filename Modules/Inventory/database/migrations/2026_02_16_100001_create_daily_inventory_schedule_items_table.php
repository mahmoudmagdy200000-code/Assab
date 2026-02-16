<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_inventory_schedule_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('daily_inventory_schedule_id');
            $table->uuid('item_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['daily_inventory_schedule_id', 'item_id']);
            $table->foreign('daily_inventory_schedule_id')->references('id')->on('daily_inventory_schedules')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_inventory_schedule_items');
    }
};
