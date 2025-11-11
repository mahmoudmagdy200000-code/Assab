<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_timelines', function (Blueprint $table) {
             $table->uuid('id');
            $table->foreignUuid('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('user_type'); // NEW
            $table->string('action');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('purchase_return_id');
            $table->index(['user_id', 'user_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_timelines');
    }
};
