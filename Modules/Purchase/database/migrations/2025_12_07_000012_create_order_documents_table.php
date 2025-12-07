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
        if (Schema::hasTable('order_documents')) {
            return;
        }

        Schema::create('order_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Polymorphic relationship
            $table->uuidMorphs('documentable');

            // Document type
            $table->enum('type', [
                'invoice',
                'delivery_note',
                'receipt_without_document',
                'quality_certificate',
                'photo',
                'other'
            ]);

            // File information
            $table->string('file_path');
            $table->string('file_name');
            $table->string('original_name');
            $table->string('mime_type');
            $table->integer('file_size');

            // Description
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            // Uploaded by
            $table->uuid('uploaded_by');
            $table->string('uploaded_by_type');

            // Status
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Indexes (uuidMorphs already creates index for documentable_type and documentable_id)
            $table->index('type');
            $table->index('uploaded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_documents');
    }
};
