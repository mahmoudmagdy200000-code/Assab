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
        if (Schema::hasTable('monthly_inventory_timelines')) {
            return;
        }

        Schema::create('monthly_inventory_timelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('monthly_inventory_id');

            $table->string('event_type', 64);

            $table->string('old_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();

            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 64)->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_image')->nullable();
            $table->string('actor_role', 64)->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('monthly_inventory_id');
            $table->index('event_type');
            $table->index('actor_id');
            $table->index('occurred_at');

            $table->foreign('monthly_inventory_id')
                ->references('id')
                ->on('monthly_inventories')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_inventory_timelines');
    }
};
