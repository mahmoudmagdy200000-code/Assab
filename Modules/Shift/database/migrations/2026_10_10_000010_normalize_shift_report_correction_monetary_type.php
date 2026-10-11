<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shift_report_corrections')) {
            // Normalize the unit label only; immutable evidence values and IDs stay intact.
            DB::table('shift_report_corrections')->where('field_type', 'monetary')
                ->update(['field_type' => 'monetary_halalas']);
        }
    }

    public function down(): void
    {
        // A metadata correction is intentionally monotonic. Do not reintroduce
        // the ambiguous label or rewrite evidence when rolling back other schema.
    }
};
