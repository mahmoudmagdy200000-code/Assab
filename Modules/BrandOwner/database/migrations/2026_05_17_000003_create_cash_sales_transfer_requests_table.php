<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sales_transfer_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Sender (the cashier or branch manager performing the transfer)
            $table->uuid('sender_id');
            $table->string('sender_type'); // cashier|branch_manager
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();

            // Recipient (Brand Owner)
            $table->uuid('brand_owner_id')->nullable();

            $table->decimal('handover_amount', 12, 2);
            $table->enum('handover_method', ['Cash Handover', 'Bank Transfer']);
            $table->timestamp('handover_date');
            $table->text('additional_notes')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['sender_id', 'sender_type']);
            $table->index('brand_owner_id');
            $table->index('status');
            $table->index('handover_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sales_transfer_requests');
    }
};
