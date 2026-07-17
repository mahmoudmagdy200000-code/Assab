<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The suppliers upload template's «رقم المورد» column had nowhere to land, so
 * the code the client types was parsed and dropped.
 *
 * Deliberately NOT unique: manual creates and approveSupplierRequest produce
 * code-less suppliers, and the same code may legitimately recur across brands —
 * a unique key would make repeat uploads collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_suppliers', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_suppliers', 'code')) {
                $t->string('code', 40)->nullable()->after('name')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('asab_suppliers', function (Blueprint $t) {
            if (Schema::hasColumn('asab_suppliers', 'code')) {
                $t->dropColumn('code');
            }
        });
    }
};
