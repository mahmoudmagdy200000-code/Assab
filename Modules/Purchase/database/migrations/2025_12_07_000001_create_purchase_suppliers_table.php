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
        if (Schema::hasTable('purchase_suppliers')) {
            return;
        }

        Schema::create('purchase_suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_id')->nullable();

            // Status and availability
            $table->enum('status', ['online', 'offline', 'away'])->default('offline');
            $table->boolean('is_active')->default(true);

            // Contact methods available
            $table->json('contact_methods')->nullable(); // ['email', 'whatsapp', 'app', 'sms']

            // Delivery information
            $table->integer('default_delivery_hours')->nullable();
            $table->decimal('min_order_amount', 12, 2)->nullable();

            // Response statistics
            $table->decimal('average_response_time_hours', 8, 2)->nullable();
            $table->decimal('response_rate_percentage', 5, 2)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->integer('total_orders')->default(0);
            $table->integer('completed_orders')->default(0);

            // Categories and items
            $table->json('categories')->nullable(); // Category IDs this supplier serves

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('is_active');
            $table->index('rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_suppliers');
    }
};
