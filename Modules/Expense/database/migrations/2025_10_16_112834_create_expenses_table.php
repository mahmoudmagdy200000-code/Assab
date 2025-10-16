<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Main expenses table
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_manager_id')->constrained('branch_managers')->onDelete('cascade');
            $table->string('expense_name');
            $table->enum('expense_type', ['quick_cash', 'single_invoice', 'grouped_invoices', 'pre_approval']);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('net_amount', 15, 2)->nullable();
            $table->decimal('vat_amount', 15, 2)->nullable();
            $table->boolean('has_vat')->default(false);
            $table->date('expense_date');
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->enum('payment_method', ['cash', 'supplier', 'custody'])->nullable();
            $table->enum('payment_type', ['full', 'partial', 'deferred'])->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('amount_paid', 15, 2)->nullable();
            $table->date('credit_due_date')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('tax_id')->nullable();
            $table->date('issue_date')->nullable();
            $table->boolean('is_tax_invoice')->default(false);
            $table->text('rejection_reason')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('branch_managers')->onDelete('set null');
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('branch_managers')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('viewed_by')->nullable()->constrained('branch_managers')->onDelete('set null');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'expense_type', 'status']);
            $table->index(['expense_date', 'status']);
        });

        // Quick cash specific details
        Schema::create('quick_cash_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->decimal('custody_balance', 15, 2)->nullable();
            $table->timestamps();
        });

        // Pre-approval request details
        Schema::create('pre_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->text('purpose');
            $table->decimal('estimated_amount', 15, 2);
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->timestamps();
        });

        // Grouped invoices header
        Schema::create('grouped_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->integer('number_of_suppliers');
            $table->integer('total_invoices');
            $table->timestamps();
        });

        // Individual invoices in grouped expenses
        Schema::create('invoice_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->foreignId('grouped_invoice_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->string('invoice_number');
            $table->date('issue_date');
            $table->string('tax_id')->nullable();
            $table->boolean('is_tax_invoice')->default(false);
            $table->decimal('net_amount', 15, 2);
            $table->decimal('vat_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->timestamps();
        });

        // Expense items (purchases)
        Schema::create('expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_detail_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('item_name');
            $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->onDelete('set null');
            $table->integer('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->timestamps();
        });

        // Expense line items (operational expenses)
        Schema::create('expense_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_detail_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('expense_name');
            $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->onDelete('set null');
            $table->decimal('price', 15, 2);
            $table->timestamps();
        });

        // Quick cash itemized list
        Schema::create('quick_cash_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->string('item_title');
            $table->decimal('item_amount', 15, 2);
            $table->timestamps();
        });

        // File attachments
        Schema::create('expense_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_detail_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type');
            $table->bigInteger('file_size');
            $table->enum('attachment_type', ['invoice_receipt', 'document'])->default('invoice_receipt');
            $table->timestamps();
        });

        // Timeline tracking
        Schema::create('expense_timelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_manager_id')->constrained('branch_managers')->onDelete('cascade');
            $table->enum('action', ['submit', 'view', 'approve', 'reject', 'edit', 'resubmit']);
            $table->enum('status', ['submitted', 'viewed', 'approved', 'rejected', 'edited', 'resubmitted']);
            $table->json('edited_fields')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['expense_id', 'created_at']);
        });

        // Categories for items and expenses
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->onDelete('cascade');
            $table->enum('type', ['item', 'expense'])->default('item');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Suppliers
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('expense_timelines');
        Schema::dropIfExists('expense_attachments');
        Schema::dropIfExists('quick_cash_items');
        Schema::dropIfExists('expense_lines');
        Schema::dropIfExists('expense_items');
        Schema::dropIfExists('invoice_details');
        Schema::dropIfExists('grouped_invoices');
        Schema::dropIfExists('pre_approval_requests');
        Schema::dropIfExists('quick_cash_expenses');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('suppliers');
    }
};
