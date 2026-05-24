<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_owner_setting_retentions')) {
            return;
        }

        Schema::create('brand_owner_setting_retentions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_owner_id')->unique();

            $table->unsignedInteger('photo_retention_years')->default(3);
            $table->unsignedInteger('handover_reports_retention_years')->default(5);

            $table->timestamps();

            $table->foreign('brand_owner_id', 'bo_setting_retention_fk')
                ->references('id')
                ->on('brand_owners')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_owner_setting_retentions');
    }
};
