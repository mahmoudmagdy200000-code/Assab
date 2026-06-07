<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch monthly sales target (halalas) powering brand-performance
 * achievement % and "branches above target" (MISSING_Dashboard §5). Additive,
 * nullable — existing rows untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches') || Schema::hasColumn('branches', 'asab_monthly_target')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('asab_monthly_target')->nullable(); // halalas
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
