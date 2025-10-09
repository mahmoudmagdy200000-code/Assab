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
        Schema::create('shift_variance_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained('cashier_shifts')->cascadeOnDelete();
            $table->decimal('variance_amount', 12, 2);
            $table->decimal('variance_percentage', 5, 2);
            $table->enum('alert_type', ['minor', 'major', 'critical']);
            $table->boolean('is_acknowledged')->default(false);
            $table->foreignId('acknowledged_by')->nullable()->constrained('branch_managers');
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('cashier_shift_id');
            $table->index('is_acknowledged');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_variance_alerts');
    }
};
