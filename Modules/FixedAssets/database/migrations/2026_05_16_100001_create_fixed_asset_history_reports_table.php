<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_history_reports')) {
            return;
        }

        Schema::create('fixed_asset_history_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->uuid('branch_id');
            $table->uuid('generated_by_id')->nullable();

            $table->string('file_name');
            $table->string('path');
            $table->timestamp('generated_at');

            $table->timestamps();

            $table->index('asset_id');
            $table->index('branch_id');
            $table->index('generated_by_id');

            $table->foreign('asset_id')
                ->references('id')
                ->on('fixed_assets')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign('generated_by_id')
                ->references('id')
                ->on('branch_managers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_history_reports');
    }
};
