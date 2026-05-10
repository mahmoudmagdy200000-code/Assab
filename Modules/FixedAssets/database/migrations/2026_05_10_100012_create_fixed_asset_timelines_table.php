<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_timelines')) {
            return;
        }

        Schema::create('fixed_asset_timelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('timelineable_type');
            $table->uuid('timelineable_id');

            $table->string('event_type', 64);
            $table->string('name');
            $table->string('actor_image_path')->nullable();
            $table->uuid('actor_id')->nullable();

            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->index(['timelineable_type', 'timelineable_id'], 'timelines_timelineable_idx');
            $table->index('event_type');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_timelines');
    }
};
