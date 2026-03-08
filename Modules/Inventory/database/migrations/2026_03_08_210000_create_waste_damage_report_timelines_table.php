<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('waste_damage_report_timelines')) {
            return;
        }

        Schema::create('waste_damage_report_timelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('waste_damage_report_id');

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

            $table->index('waste_damage_report_id');
            $table->index('event_type');
            $table->index('occurred_at');

            $table->foreign('waste_damage_report_id')
                ->references('id')
                ->on('waste_damage_reports')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_damage_report_timelines');
    }
};
