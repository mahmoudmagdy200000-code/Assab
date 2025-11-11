<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
             $table->uuid('id');
            $table->string('order_number')->unique();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_manager_id');
            $table->string('user_type'); // NEW: stores model class name
            $table->enum('order_type', ['direct_supplier', 'purchasing_officer', 'internal_transfer', 'multiple_sources']);
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->unsignedBigInteger('purchasing_officer_id')->nullable();
            $table->string('purchasing_officer_type')->nullable(); // NEW
            $table->foreignUuid('transfer_from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('status', [
                'pending',
                'pending_confirmation',
                'pending_approval',
                'partial_confirmation',
                'confirmed',
                'preparing',
                'on_the_way',
                'delivered',
                'rejected',
                'canceled',
                'draft'
            ])->default('draft');
            $table->enum('priority', ['high', 'normal', 'low'])->default('normal');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->integer('total_items')->default(0);
            $table->date('delivery_date')->nullable();
            $table->date('latest_delivery_date')->nullable();
            $table->text('special_instructions')->nullable();
            $table->text('message')->nullable();
            $table->json('notification_methods')->nullable();
            $table->timestamp('requested_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('rejected_by_id')->nullable();
            $table->string('rejected_by_type')->nullable(); // NEW
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index('order_type');
            $table->index('created_at');
            $table->index(['branch_manager_id', 'user_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
