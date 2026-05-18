<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `custody_requests` MODIFY `preferred_receipt_method` ENUM('Cash Handover','Bank Transfer') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `custody_requests` MODIFY `preferred_receipt_method` ENUM('Cash Handover','Bank Transfer') NOT NULL");
    }
};
