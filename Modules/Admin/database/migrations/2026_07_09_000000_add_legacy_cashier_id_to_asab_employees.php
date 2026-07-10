<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Traceability link from the dashboard employee registry to the legacy
     * `cashiers` row provisioned for cashier-role employees (WS2 bridge).
     */
    public function up(): void
    {
        Schema::table('asab_employees', function (Blueprint $t) {
            $t->uuid('legacy_cashier_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('asab_employees', function (Blueprint $t) {
            $t->dropColumn('legacy_cashier_id');
        });
    }
};
