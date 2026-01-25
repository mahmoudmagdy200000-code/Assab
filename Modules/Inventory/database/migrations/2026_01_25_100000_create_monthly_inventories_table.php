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
        if (Schema::hasTable('monthly_inventories')) {
            return;
        }

        Schema::create('monthly_inventories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('inventory_number')->unique();

            $table->uuid('branch_id');
            $table->uuid('created_by');

            $table->date('inventory_date');
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->integer('time_taken')->nullable();
            $table->unsignedSmallInteger('number_of_products')->default(0);
            $table->unsignedSmallInteger('expected_time_minutes')->nullable();

            $table->string('status', 32)->default('in_progress');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('created_by');
            $table->index('status');
            $table->index('inventory_date');
            $table->index(['branch_id', 'inventory_date']);

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign('created_by')
                ->references('id')
                ->on('branch_managers')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_inventories');
    }
};
