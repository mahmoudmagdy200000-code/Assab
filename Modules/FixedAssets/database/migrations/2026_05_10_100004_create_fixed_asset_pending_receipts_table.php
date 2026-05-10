<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_pending_receipts')) {
            return;
        }

        Schema::create('fixed_asset_pending_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('asset_name');
            $table->string('asset_code');
            $table->string('asset_image')->nullable();

            $table->uuid('recipient_branch_id');
            $table->string('source', 32)->default('from_branch');
            $table->string('status', 32)->default('pending');

            $table->timestamp('received_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('recipient_branch_id');
            $table->index('status');
            $table->index('source');

            $table->foreign('recipient_branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_pending_receipts');
    }
};
