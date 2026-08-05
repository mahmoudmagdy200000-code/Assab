<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Shift\Enums\ShiftStatus;

/**
 * 2026-08-05, production: saving a cashier died with
 *   SQLSTATE[01000]: Warning: 1265 Data truncated for column 'status' at row 1
 *   (update `cashier_shifts` set `status` = cancelled … )
 *
 * `cashier_shifts.status` was created as ENUM('not_started','in_progress',
 * 'completed','reassigned') — it has no cancel member at all, while
 * `ShiftStatus` (the PHP enum the model casts to) does. Deactivating a cashier
 * cancels their future shifts, so that write could never succeed and the whole
 * cashier save rolled back with a raw SQL dialog in the app.
 *
 * The column becomes a plain string validated by `ShiftStatus` at the app layer
 * (the same choice already made for the custody timeline): a new member is then
 * a code change, not a production DDL on a hot table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->string('status', 20)->default(ShiftStatus::NOT_STARTED->value)->change();
        });

        // Had strict mode been off, MySQL would have stored the truncated value
        // as '' instead of erroring — and the model's enum cast throws on read,
        // so such a row 500s every shift screen it appears on. Those rows are
        // exactly the cancels that never landed.
        DB::table('cashier_shifts')->where('status', '')->update([
            'status' => ShiftStatus::CANCELED->value,
        ]);
    }

    public function down(): void
    {
        // The old enum has nowhere to put a cancelled shift; park them back on
        // the pending state rather than losing the row to a failed DDL.
        DB::table('cashier_shifts')->where('status', ShiftStatus::CANCELED->value)->update([
            'status' => ShiftStatus::NOT_STARTED->value,
        ]);

        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->enum('status', ['not_started', 'in_progress', 'completed', 'reassigned'])
                ->default(ShiftStatus::NOT_STARTED->value)
                ->change();
        });
    }
};
