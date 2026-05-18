<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_damage_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('waste_damage_reports', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('submitted_at');
            }
            if (!Schema::hasColumn('waste_damage_reports', 'approved_by')) {
                $table->uuid('approved_by')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('waste_damage_reports', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('waste_damage_reports', 'rejected_by')) {
                $table->uuid('rejected_by')->nullable()->after('rejected_at');
            }
            if (!Schema::hasColumn('waste_damage_reports', 'rejection_comment')) {
                $table->text('rejection_comment')->nullable()->after('rejected_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('waste_damage_reports', function (Blueprint $table) {
            $table->dropColumn([
                'approved_at',
                'approved_by',
                'rejected_at',
                'rejected_by',
                'rejection_comment',
            ]);
        });
    }
};
