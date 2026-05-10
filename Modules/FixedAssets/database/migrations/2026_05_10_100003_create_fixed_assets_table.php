<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_assets')) {
            return;
        }

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('image')->nullable();

            $table->uuid('branch_id');
            $table->uuid('zone_id')->nullable();
            $table->uuid('asset_type_id')->nullable();

            $table->string('assigned_to_type')->nullable();
            $table->uuid('assigned_to_id')->nullable();

            $table->string('status', 32)->default('excellent');
            $table->decimal('value', 12, 2)->default(0);

            $table->timestamp('acquired_at')->nullable();
            $table->timestamp('custody_started_at')->nullable();
            $table->timestamp('last_updated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('zone_id');
            $table->index('asset_type_id');
            $table->index('status');
            $table->index(['branch_id', 'status']);
            $table->index(['assigned_to_type', 'assigned_to_id']);

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign('zone_id')
                ->references('id')
                ->on('asset_zones')
                ->nullOnDelete();

            $table->foreign('asset_type_id')
                ->references('id')
                ->on('asset_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
