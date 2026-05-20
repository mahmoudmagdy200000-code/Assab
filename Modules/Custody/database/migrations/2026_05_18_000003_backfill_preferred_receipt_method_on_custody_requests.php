<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('custody_requests')
            ->whereNull('preferred_receipt_method')
            ->update(['preferred_receipt_method' => 'Cash Handover']);
    }

    public function down(): void {}
};
