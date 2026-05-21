<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('custody_requests', function (Blueprint $table) {
                $table->string('preferred_receipt_method')->nullable()->change();
            });

            return;
        }

        DB::statement("ALTER TABLE `custody_requests` MODIFY `preferred_receipt_method` ENUM('Cash Handover','Bank Transfer') NULL");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('custody_requests', function (Blueprint $table) {
                $table->string('preferred_receipt_method')->nullable(false)->change();
            });

            return;
        }

        DB::statement("ALTER TABLE `custody_requests` MODIFY `preferred_receipt_method` ENUM('Cash Handover','Bank Transfer') NOT NULL");
    }
};
