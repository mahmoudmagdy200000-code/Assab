<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «الرصيد الافتتاحي» was dead config (2026-08-10).
 *
 * The accountant sets an opening float per schedule on the dashboard
 * (`asab_brand_shift_configs.shifts.openingFloatHalalas`, ACC «تعيين الشفتات»),
 * but nothing ever carried it into the mobile world: every `cashier_shifts` row
 * was created with `opening_balance = 0`, so the app's shift card read
 * «Opening balance: Not yet recorded» on the first shift of every day and only
 * a cashier-to-cashier handover ever set a figure.
 *
 * The float belongs on the shift TEMPLATE — it is a property of the schedule,
 * the same thing the regenerate bridge already projects into this table — so a
 * cashier shift can default from it at creation time without the Shift module
 * reaching into the dashboard's config tables.
 *
 * SAR (2dp) to match `cashier_shifts.opening_balance`; the dashboard side stays
 * in halalas and converts at the boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $t) {
            $t->decimal('opening_float', 12, 2)->default(0)->after('end_time');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $t) {
            $t->dropColumn('opening_float');
        });
    }
};
