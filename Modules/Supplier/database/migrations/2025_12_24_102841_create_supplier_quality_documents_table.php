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
        if (Schema::hasTable('supplier_quality_documents')) {
            return;
        }

        Schema::create('supplier_quality_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id');
            $table->uuid('order_id')->nullable(); // If document is order-specific
            $table->uuid('product_id')->nullable(); // If document is product-specific
            $table->string('document_type'); // certificate, test_report, compliance_doc, batch_info
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_type')->nullable(); // pdf, jpg, png, etc.
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('issuing_authority')->nullable();
            $table->string('certificate_number')->nullable();
            $table->json('metadata')->nullable(); // Additional document metadata
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->cascadeOnDelete();

            $table->index('supplier_id');
            $table->index('order_id');
            $table->index('product_id');
            $table->index('document_type');
            $table->index('expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_quality_documents');
    }
};
