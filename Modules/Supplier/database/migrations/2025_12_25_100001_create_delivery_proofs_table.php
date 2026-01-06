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
        if (Schema::hasTable('delivery_proofs')) {
            return;
        }

        Schema::create('delivery_proofs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id');
            $table->string('recipient_name');
            $table->string('recipient_signature')->nullable();
            $table->json('delivery_photos')->nullable();
            $table->text('condition_confirmation')->nullable();
            $table->timestamp('acknowledgment_received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->onDelete('cascade');

            $table->index('purchase_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_proofs');
    }
};

