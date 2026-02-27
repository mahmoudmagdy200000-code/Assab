<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_variance_details', function (Blueprint $table) {
            $table->enum('responsibility_status', ['pending', 'approved', 'rejected'])
                ->default('pending')
                ->after('supporting_files');

            $table->text('rejection_reason')->nullable()->after('responsibility_status');
            $table->string('reviewed_by_id')->nullable()->after('rejection_reason');
            $table->string('reviewed_by_type')->nullable()->after('reviewed_by_id');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_type');

            $table->index('responsibility_status');
        });
    }

    public function down(): void
    {
        Schema::table('shift_variance_details', function (Blueprint $table) {
            $table->dropIndex(['responsibility_status']);
            $table->dropColumn([
                'responsibility_status',
                'rejection_reason',
                'reviewed_by_id',
                'reviewed_by_type',
                'reviewed_at',
            ]);
        });
    }
};
