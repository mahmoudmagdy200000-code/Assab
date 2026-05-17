<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_owners', function (Blueprint $table) {
            $table->boolean('is_first_login')->default(true)->after('is_active');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
        });

        Schema::create('brand_owner_otps', function (Blueprint $table) {
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
        Schema::dropIfExists('brand_owner_otps');
        Schema::table('brand_owners', function (Blueprint $table) {
            $table->dropColumn(['is_first_login', 'phone_verified_at']);
        });
    }
};
