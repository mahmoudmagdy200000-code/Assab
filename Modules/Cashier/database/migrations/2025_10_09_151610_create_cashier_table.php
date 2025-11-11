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
        Schema::create('cashiers', function (Blueprint $table) {
            $table->uuid('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone', 20)->nullable();
            $table->string('image')->nullable();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->enum('status', ['active', 'pending', 'deactivated'])->default('pending');
            $table->foreignUuid('created_by')->constrained('branch_managers');
            $table->timestamps();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();


            $table->index('branch_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashier');
    }
};
