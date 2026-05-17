<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custody_requests', function (Blueprint $table) {
            $table->uuid('created_by_brand_owner_id')->nullable()->after('branch_id');
            $table->timestamp('handover_date')->nullable()->after('additional_notes');
            $table->string('approved_by_type')->nullable()->after('approved_by');
            $table->string('rejected_by_type')->nullable()->after('rejected_by');
            $table->index('created_by_brand_owner_id');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'sqlite') {
            try {
                Schema::table('custody_requests', function (Blueprint $table) {
                    $table->dropForeign(['approved_by']);
                });
            } catch (\Throwable $e) {
            }
            try {
                Schema::table('custody_requests', function (Blueprint $table) {
                    $table->dropForeign(['rejected_by']);
                });
            } catch (\Throwable $e) {
            }
        }
    }

    public function down(): void
    {
        Schema::table('custody_requests', function (Blueprint $table) {
            $table->dropIndex(['created_by_brand_owner_id']);
            $table->dropColumn([
                'created_by_brand_owner_id',
                'handover_date',
                'approved_by_type',
                'rejected_by_type',
            ]);
        });
    }
};
