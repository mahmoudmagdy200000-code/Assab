<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_request_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('custody_request_id')->constrained('custody_requests')->cascadeOnDelete();

            $table->string('file_name');
            $table->string('original_name');
            $table->string('file_path');
            $table->string('file_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('custody_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_request_attachments');
    }
};
