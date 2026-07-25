<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal-vs-external supplier (meeting §10.2 / FR-PUR-2): the two kinds were
 * never represented, so «إرسال للمورد» could not route internal suppliers to
 * their in-app order and external ones to WhatsApp only.
 *
 *  - internal (default, false) — a supplier the company/procurement registered;
 *    has a mobile+dashboard presence and takes orders in-app.
 *  - external (true) — a supplier a branch surfaced (approveSupplierRequest);
 *    dealt with outside the platform, reachable via the WhatsApp deep link.
 *
 * Additive + backfill-safe: existing rows default to internal, which is what
 * every current supplier is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_suppliers', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_suppliers', 'is_external')) {
                $t->boolean('is_external')->default(false)->after('status')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('asab_suppliers', function (Blueprint $t) {
            if (Schema::hasColumn('asab_suppliers', 'is_external')) {
                $t->dropColumn('is_external');
            }
        });
    }
};
