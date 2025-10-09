<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up()
    {
        Schema::create('branch_managers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->unique()->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_first_login')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        //  OTP
        Schema::create('branch_manager_otps', function (Blueprint $table) {
            $table->id();
            $table->string('identifier'); // email or phone
            $table->string('otp');
            $table->enum('type', ['email', 'phone']);
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });

        // Password Reset Tokens
        Schema::create('branch_manager_password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Add indexes for performance
        // Schema::table('branch_manager_otps', function (Blueprint $table) {
        //     $table->index(['identifier', 'type']);
        //     $table->index('expires_at');
        // });
    }

    public function down()
    {
        Schema::dropIfExists('branch_manager_password_resets');
        Schema::dropIfExists('branch_manager_otps');
        Schema::dropIfExists('branch_managers');
    }
};
