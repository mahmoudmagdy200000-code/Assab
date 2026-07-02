<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the ASAB branch schema: the earlier add_asab_hierarchy_to_branches
 * migration added manager/status + asab_* FKs but omitted the contact/flag
 * columns that Branch $fillable and Admin\BranchController (store/update/present)
 * actually write — city, address, phone, email, is_active. Additive, nullable,
 * guarded so existing rows/code are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'city')) {
                $table->string('city', 80)->nullable();
            }
            if (! Schema::hasColumn('branches', 'address')) {
                $table->text('address')->nullable();
            }
            if (! Schema::hasColumn('branches', 'phone')) {
                $table->string('phone', 32)->nullable();
            }
            if (! Schema::hasColumn('branches', 'email')) {
                $table->string('email')->nullable();
            }
            if (! Schema::hasColumn('branches', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
