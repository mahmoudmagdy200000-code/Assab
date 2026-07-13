<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMP-4 add-branch review flow (T14.1): a tenant-created branch waits for
 * platform-admin review before it goes live. Legacy/admin-created branches
 * default to `approved` so nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'asab_review_status')) {
                $table->string('asab_review_status', 20)->default('approved')->index();
            }
            if (! Schema::hasColumn('branches', 'asab_review_note')) {
                $table->string('asab_review_note', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
