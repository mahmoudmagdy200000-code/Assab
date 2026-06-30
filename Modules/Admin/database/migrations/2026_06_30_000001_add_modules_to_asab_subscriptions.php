<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-subscription module list (Admin dashboard contract batch 1, A5).
 * Surfaced by AdminSubscription and edited via PATCH /admin/subscriptions/{id}/modules.
 * Nullable/additive, non-breaking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_subscriptions') || Schema::hasColumn('asab_subscriptions', 'modules')) {
            return;
        }

        Schema::table('asab_subscriptions', function (Blueprint $table) {
            $table->json('modules')->nullable();
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
