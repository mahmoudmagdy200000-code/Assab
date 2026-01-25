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
        if (Schema::hasTable('monthly_inventory_feedback')) {
            return;
        }

        Schema::create('monthly_inventory_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('monthly_inventory_id');

            $table->uuid('author_id')->nullable();
            $table->string('author_type', 64)->nullable();
            $table->string('author_name')->nullable();

            $table->text('message');

            $table->timestamps();

            $table->index('monthly_inventory_id');

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
        Schema::dropIfExists('monthly_inventory_feedback');
    }
};
