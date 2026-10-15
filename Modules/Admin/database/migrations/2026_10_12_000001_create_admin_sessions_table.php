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
        Schema::create('admin_sessions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('company_id')->nullable();
            $table->string('device_name', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_remember_me')->default(false);
            $table->unsignedBigInteger('generation')->default(0);
            $table->unsignedBigInteger('current_access_token_id')->nullable();
            $table->unsignedBigInteger('current_refresh_token_id')->nullable();
            $table->unsignedBigInteger('legacy_access_token_id')->nullable()->unique('uq_admin_sessions_legacy_alias');
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at'], 'idx_admin_sessions_user');
            $table->index('company_id', 'idx_admin_sessions_company');
            $table->index('expires_at', 'idx_admin_sessions_expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_sessions');
    }
};
