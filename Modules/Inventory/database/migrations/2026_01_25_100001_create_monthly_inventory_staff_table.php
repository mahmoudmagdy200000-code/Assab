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
        if (Schema::hasTable('monthly_inventory_staff')) {
            return;
        }

        Schema::create('monthly_inventory_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('monthly_inventory_id');

            $table->uuid('user_id');
            $table->string('user_type', 64);
            $table->string('role', 32);

            $table->timestamps();

            $table->index('monthly_inventory_id');
            $table->index(['user_id', 'user_type']);

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
        Schema::dropIfExists('monthly_inventory_staff');
    }
};
