<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a brand to its owner's login account (B-A6). Until now asab_brands only
 * carried the owner's display name + email as plain strings; POST /admin/brands
 * now provisions an AsabUser (role_key 'brand-owner') and stores its id here so
 * the owner can be re-resolved (reset-password) without an email match. Additive,
 * nullable, guarded — existing rows/code untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_brands') || Schema::hasColumn('asab_brands', 'owner_user_id')) {
            return;
        }

        Schema::table('asab_brands', function (Blueprint $table) {
            $table->uuid('owner_user_id')->nullable()->index()->after('owner_email');
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
