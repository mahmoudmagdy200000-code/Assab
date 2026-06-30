<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * View-tracking for sent reports (Admin dashboard contract batch 1, A10).
 * Powers GET /admin/reports/{reportKey}/status viewed/notViewed counts.
 * Nullable/additive, non-breaking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_report_distributions')) {
            return;
        }

        Schema::table('asab_report_distributions', function (Blueprint $table) {
            if (! Schema::hasColumn('asab_report_distributions', 'viewed')) {
                $table->boolean('viewed')->default(false);
            }
            if (! Schema::hasColumn('asab_report_distributions', 'viewed_at')) {
                $table->timestamp('viewed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
