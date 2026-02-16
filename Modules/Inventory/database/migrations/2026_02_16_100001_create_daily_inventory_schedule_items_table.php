<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'di_sched_items_sched_item_unique';

    private const FK_SCHEDULE = 'di_sched_items_schedule_id_fk';

    private const FK_ITEM = 'di_sched_items_item_id_fk';

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
                $table->foreign('daily_inventory_schedule_id', self::FK_SCHEDULE)->references('id')->on('daily_inventory_schedules')->cascadeOnDelete();
                $table->foreign('item_id', self::FK_ITEM)->references('id')->on('items')->cascadeOnDelete();
            });
            return;
        }

        // Table exists from a previous failed run; add missing unique and foreign keys
        $hasUnique = collect(DB::select("SHOW INDEX FROM daily_inventory_schedule_items WHERE Key_name = ?", [self::UNIQUE_INDEX]))->isNotEmpty();
        if (! $hasUnique) {
            Schema::table('daily_inventory_schedule_items', function (Blueprint $table) {
                $table->unique(['daily_inventory_schedule_id', 'item_id'], self::UNIQUE_INDEX);
            });
        }

        $fkNames = collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_inventory_schedule_items' AND REFERENCED_TABLE_NAME IS NOT NULL"))->pluck('CONSTRAINT_NAME')->all();
        Schema::table('daily_inventory_schedule_items', function (Blueprint $table) use ($fkNames) {
            if (! in_array(self::FK_SCHEDULE, $fkNames, true)) {
                $table->foreign('daily_inventory_schedule_id', self::FK_SCHEDULE)->references('id')->on('daily_inventory_schedules')->cascadeOnDelete();
            }
            if (! in_array(self::FK_ITEM, $fkNames, true)) {
                $table->foreign('item_id', self::FK_ITEM)->references('id')->on('items')->cascadeOnDelete();
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
