<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_handovers')) {
            return;
        }

        Schema::create('fixed_asset_handovers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('session_code', 64)->unique();

            $table->uuid('branch_id');
            $table->uuid('sender_id');
            $table->string('recipient_type');
            $table->uuid('recipient_id');

            $table->string('status', 32)->default('pending_approval');

            $table->text('note');
            $table->json('sent_invitations')->nullable();
            $table->string('qr_code_image_path')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('sender_id');
            $table->index('status');
            $table->index(['recipient_type', 'recipient_id']);

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_handovers');
    }
};
