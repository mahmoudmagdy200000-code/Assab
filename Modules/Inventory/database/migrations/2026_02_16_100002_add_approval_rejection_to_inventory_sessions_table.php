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
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('submitted_at');
            $table->uuid('rejected_by')->nullable()->after('rejected_at');
            $table->text('rejection_comment')->nullable()->after('rejected_by');
            $table->timestamp('approved_at')->nullable()->after('rejection_comment');
            $table->uuid('approved_by')->nullable()->after('approved_at');
        });

        // Change status enum to include new values (MySQL)
        \DB::statement("ALTER TABLE inventory_sessions MODIFY COLUMN status ENUM('draft', 'pending', 'approved', 'rejected', 'pending_your_action', 'completed') DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->dropColumn(['rejected_at', 'rejected_by', 'rejection_comment', 'approved_at', 'approved_by']);
        });

        \DB::statement("ALTER TABLE inventory_sessions MODIFY COLUMN status ENUM('draft', 'completed') DEFAULT 'draft'");
    }
};
