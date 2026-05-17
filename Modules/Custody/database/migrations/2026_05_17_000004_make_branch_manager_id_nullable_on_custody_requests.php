<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            try {
                Schema::table('custody_requests', function (Blueprint $table) {
                    $table->dropForeign(['branch_manager_id']);
                });
            } catch (\Throwable $e) {
            }
            try {
                Schema::table('custody_requests', function (Blueprint $table) {
                    $table->dropForeign(['branch_id']);
                });
            } catch (\Throwable $e) {
            }
        }

        Schema::table('custody_requests', function (Blueprint $table) {
            $table->uuid('branch_manager_id')->nullable()->change();
            $table->uuid('branch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Non-reversible without data assumptions; leave columns nullable.
    }
};
