<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_owner_setting_notifications')) {
            return;
        }

        Schema::create('brand_owner_setting_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_owner_id');
            $table->string('type', 64);
            $table->boolean('enabled')->default(false);

            $table->timestamps();

            $table->unique(['brand_owner_id', 'type'], 'bo_setting_notif_uniq');

            $table->foreign('brand_owner_id', 'bo_setting_notif_fk')
                ->references('id')
                ->on('brand_owners')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_owner_setting_notifications');
    }
};
