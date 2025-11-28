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
        Schema::table('shift_handover_status', function (Blueprint $table) {
            // Drop the old foreign key constraint
            $table->dropForeign(['reviewed_by']);

            // Remove the old reviewed_by column
            $table->dropColumn('reviewed_by');

            // Add polymorphic columns
            $table->uuid('reviewed_by_id')->nullable();
            $table->string('reviewed_by_type')->nullable();

            // Add index for polymorphic relationship
            $table->index(['reviewed_by_id', 'reviewed_by_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_handover_status', function (Blueprint $table) {
            // Remove polymorphic columns
            $table->dropIndex(['reviewed_by_id', 'reviewed_by_type']);
            $table->dropColumn(['reviewed_by_id', 'reviewed_by_type']);

            // Add back the old column
            $table->foreignUuid('reviewed_by')->nullable()->constrained('cashiers')->nullOnDelete();
        });
    }
};
