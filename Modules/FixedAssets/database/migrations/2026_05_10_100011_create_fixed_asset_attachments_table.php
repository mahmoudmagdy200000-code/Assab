<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_attachments')) {
            return;
        }

        Schema::create('fixed_asset_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('attachable_type');
            $table->uuid('attachable_id');
            $table->string('kind', 64);

            $table->string('file_name');
            $table->string('file_type', 128);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('path');

            $table->uuid('uploaded_by_id')->nullable();
            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id'], 'attachments_attachable_idx');
            $table->index('kind');
            $table->index('uploaded_by_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_attachments');
    }
};
