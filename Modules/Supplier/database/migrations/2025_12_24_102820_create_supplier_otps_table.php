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
        if (Schema::hasTable('supplier_otps')) {
            return;
        }

        Schema::create('supplier_otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('identifier'); // email or phone
            $table->string('otp');
            $table->enum('type', ['email', 'phone']);
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(false);
            $table->timestamps();

            $table->index(['identifier', 'type']);
            $table->index('expires_at');
            $table->index('is_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_otps');
    }
};
