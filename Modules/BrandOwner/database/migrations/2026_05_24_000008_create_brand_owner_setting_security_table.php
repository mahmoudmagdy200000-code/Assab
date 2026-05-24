<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_owner_setting_securities')) {
            return;
        }

        Schema::create('brand_owner_setting_securities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_owner_id')->unique();

            $table->boolean('data_encryption')->default(true);
            $table->boolean('daily_backup_enabled')->default(true);
            $table->unsignedInteger('daily_backup_interval_hours')->default(24);
            $table->boolean('monthly_security_audit')->default(false);

            $table->timestamps();

            $table->foreign('brand_owner_id', 'bo_setting_security_fk')
                ->references('id')
                ->on('brand_owners')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_owner_setting_securities');
    }
};
