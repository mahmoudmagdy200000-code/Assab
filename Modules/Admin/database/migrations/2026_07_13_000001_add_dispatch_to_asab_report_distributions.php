<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RPT-3 owner dispatch (T15.3): the send payload now carries a delivery format
 * (pdf/excel/both) and an optional cover message. Nullable/additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_report_distributions')) {
            return;
        }

        Schema::table('asab_report_distributions', function (Blueprint $table) {
            if (! Schema::hasColumn('asab_report_distributions', 'format')) {
                $table->string('format', 32)->nullable();
            }
            if (! Schema::hasColumn('asab_report_distributions', 'cover_message')) {
                $table->text('cover_message')->nullable();
            }
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
