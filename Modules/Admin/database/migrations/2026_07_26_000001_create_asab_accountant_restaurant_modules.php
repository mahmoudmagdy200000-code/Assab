<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-(accountant, restaurant) module permissions — ADM-3.3.
 *
 * The accountant module grid used to write ONE flat list
 * (`asab_user_roles.module_keys`), so ticking a module for one restaurant ticked
 * it for every restaurant and the grid could not be read back per row (the
 * "0/9" the client reported). This table is the real per-cell store.
 *
 * `asab_user_roles.module_keys` is KEPT as the union of these rows: tenant/auth
 * resolution (ResolveTenant, AuthService) reads that one flat array, and this
 * change must not alter what those two see.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asab_accountant_restaurant_modules')) {
            return;
        }

        Schema::create('asab_accountant_restaurant_modules', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id')->primary();
            $t->uuid('accountant_id');
            $t->uuid('restaurant_id')->index();
            $t->json('module_keys')->nullable();
            $t->uuid('updated_by_id')->nullable();
            $t->timestamps();
            // The grid is always read as "every row of ONE accountant" and
            // written one cell at a time, so this compound index serves the
            // read AND guards the upsert against duplicate cells.
            $t->unique(['accountant_id', 'restaurant_id'], 'asab_acc_rest_modules_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_accountant_restaurant_modules');
    }
};
