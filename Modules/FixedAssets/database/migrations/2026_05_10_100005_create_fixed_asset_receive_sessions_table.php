<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_receive_sessions')) {
            return;
        }

        Schema::create('fixed_asset_receive_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipient_branch_id');
            $table->uuid('received_by_id');
            $table->string('type', 32);
            $table->timestamp('received_at')->nullable();

            $table->timestamps();

            $table->index('recipient_branch_id');
            $table->index('received_by_id');
            $table->index('type');

            $table->foreign('recipient_branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign('received_by_id')
                ->references('id')
                ->on('branch_managers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_receive_sessions');
    }
};
