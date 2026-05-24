<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_managers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_first_login')->default(false);
            $table->enum('status', ['active', 'pending', 'suspended'])->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('is_active');
        });

        Schema::create('brand_manager_otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('identifier');
            $table->string('otp');
            $table->string('type');
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(false);
            $table->timestamps();

            $table->index(['identifier', 'type']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_manager_otps');
        Schema::dropIfExists('brand_managers');
    }
};
