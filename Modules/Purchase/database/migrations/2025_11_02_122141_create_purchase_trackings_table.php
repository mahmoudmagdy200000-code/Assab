<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('purchase_trackings', function (Blueprint $table) {
         $table->uuid('id');
        $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
        $table->enum('status', ['preparing', 'out_for_delivery', 'delivered']);
        $table->string('driver_name')->nullable();
        $table->string('driver_phone')->nullable();
        $table->string('driver_image')->nullable();
        $table->string('vehicle_number')->nullable();
        $table->timestamp('estimated_arrival')->nullable();
        $table->timestamp('actual_arrival')->nullable();
        $table->text('delivery_address')->nullable();
        $table->text('delivery_notes')->nullable();
        $table->json('quality_certificates')->nullable();
        $table->decimal('temperature', 5, 2)->nullable();
        $table->unsignedBigInteger('updated_by_id');
        $table->string('updated_by_type'); // NEW
        $table->timestamps();

        $table->index('purchase_order_id');
        $table->index(['updated_by_id', 'updated_by_type']);
    });
}

    public function down(): void
    {
        Schema::dropIfExists('purchase_trackings');
    }
};
