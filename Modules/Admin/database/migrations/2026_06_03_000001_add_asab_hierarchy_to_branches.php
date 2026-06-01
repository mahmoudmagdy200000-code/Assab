<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links the existing (reused) `branches` table into the ASAB SaaS hierarchy
 * (company → brand → restaurant → branch). All columns nullable & guarded so
 * existing rows/code are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            foreach ([
                'asab_company_id' => 'uuid',
                'asab_brand_id' => 'uuid',
                'asab_restaurant_id' => 'uuid',
                'asab_manager_user_id' => 'uuid',
            ] as $col => $type) {
                if (! Schema::hasColumn('branches', $col)) {
                    $table->uuid($col)->nullable()->index();
                }
            }
            if (! Schema::hasColumn('branches', 'manager')) {
                $table->string('manager', 200)->nullable();
            }
            if (! Schema::hasColumn('branches', 'status')) {
                $table->string('status', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
