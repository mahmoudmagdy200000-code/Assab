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
            // Manager approval status (separate from main status)
            $table->enum('manager_approval_status', ['pending', 'approved', 'rejected', 'rejected_final'])
                ->default('pending')
                ->after('status');

            // Rejection tracking for 2-rejection rule
            $table->integer('rejection_count')->default(0)->after('rejection_reason');
            $table->timestamp('first_rejected_at')->nullable()->after('rejection_count');
            $table->timestamp('second_rejected_at')->nullable()->after('first_rejected_at');

            // Edit tracking after rejection
            $table->boolean('was_edited_after_rejection')->default(false)->after('second_rejected_at');
            $table->timestamp('edited_at')->nullable()->after('was_edited_after_rejection');

            // Add index for performance
            $table->index('manager_approval_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_handover_status', function (Blueprint $table) {
            $table->dropIndex(['manager_approval_status']);

            $table->dropColumn([
                'manager_approval_status',
                'rejection_count',
                'first_rejected_at',
                'second_rejected_at',
                'was_edited_after_rejection',
                'edited_at'
            ]);
        });
    }
};
