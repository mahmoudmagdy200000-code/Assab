<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_owner_setting_reports')) {
            return;
        }

        Schema::create('brand_owner_setting_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_owner_id')->unique();

            $table->json('monthly_reports')->nullable();
            $table->json('quarterly_reports')->nullable();
            $table->json('annual_reports')->nullable();

            $table->timestamps();

            $table->foreign('brand_owner_id', 'bo_setting_report_fk')
                ->references('id')
                ->on('brand_owners')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_owner_setting_reports');
    }
};
