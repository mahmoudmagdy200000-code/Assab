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
        Schema::table('branch_manager_shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('branch_manager_shifts', 'handover_from')) {
                $table->uuid('handover_from')->nullable()->after('next_manager_id');
                $table->foreign('handover_from')->references('id')->on('branch_managers')->nullOnDelete();
            }

            if (! Schema::hasColumn('branch_manager_shifts', 'handover_to')) {
                $table->uuid('handover_to')->nullable()->after('handover_from');
                $table->foreign('handover_to')->references('id')->on('branch_managers')->nullOnDelete();
            }

            if (! Schema::hasColumn('branch_manager_shifts', 'handover_amount')) {
                $table->decimal('handover_amount', 10, 2)->default(0)->after('handover_to');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_manager_shifts', function (Blueprint $table) {
            $table->dropForeign(['handover_from']);
            $table->dropForeign(['handover_to']);
            $table->dropColumn(['handover_from', 'handover_to', 'handover_amount']);
        });
    }
};
