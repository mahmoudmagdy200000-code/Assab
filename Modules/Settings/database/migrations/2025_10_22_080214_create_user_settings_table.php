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
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('user_type', 50); // 'branch_manager' or 'cashier'

            // System Settings
            $table->enum('language', ['ar', 'en'])->default('ar');
            $table->enum('theme', ['light', 'dark'])->default('light');

            // Notification Settings
            $table->boolean('notification_shift_variance')->default(true);
            $table->boolean('notification_daily_inventory')->default(true);
            $table->boolean('notification_approved_aggregators')->default(true);
            $table->boolean('notification_asset_transfers')->default(true);
            $table->boolean('notification_split_shift_handover')->default(true);

            $table->timestamps();

            $table->unique(['user_id', 'user_type']);
            $table->index('user_id');
            $table->index('user_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
