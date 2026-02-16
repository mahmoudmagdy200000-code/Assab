<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'di_sched_items_sched_item_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('daily_inventory_schedule_items')) {
            Schema::create('daily_inventory_schedule_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('daily_inventory_schedule_id');
                $table->uuid('item_id');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['daily_inventory_schedule_id', 'item_id'], self::UNIQUE_INDEX);
                $table->foreign('daily_inventory_schedule_id')->references('id')->on('daily_inventory_schedules')->cascadeOnDelete();
                $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            });
            return;
        }

        // Table exists from a previous failed run (e.g. long index name); add missing constraints
        Schema::table('daily_inventory_schedule_items', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexListing('daily_inventory_schedule_items')))
                ->pluck('name')
                ->all();
            if (! in_array(self::UNIQUE_INDEX, $indexes, true)) {
                $table->unique(['daily_inventory_schedule_id', 'item_id'], self::UNIQUE_INDEX);
            }
        });

        $foreignKeys = collect(Schema::getForeignKeyListing('daily_inventory_schedule_items')))
            ->pluck('name')
            ->all();
        Schema::table('daily_inventory_schedule_items', function (Blueprint $table) use ($foreignKeys) {
            if (! in_array('daily_inventory_schedule_items_daily_inventory_schedule_id_foreign', $foreignKeys, true)) {
                $table->foreign('daily_inventory_schedule_id')->references('id')->on('daily_inventory_schedules')->cascadeOnDelete();
            }
            if (! in_array('daily_inventory_schedule_items_item_id_foreign', $foreignKeys, true)) {
                $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            }
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
