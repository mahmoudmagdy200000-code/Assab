<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional accountant note on a fixed asset — captured at registration and on
 * accountant edits (Accountant assets flow). Nullable/additive, non-breaking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_assets') || Schema::hasColumn('asab_assets', 'notes')) {
            return;
        }

        Schema::table('asab_assets', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
