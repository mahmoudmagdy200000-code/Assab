<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T12.3 — `origin` (§5.2b) stays a 3-value business enum (mobile|procurement|
 * system); `channel` records the physical surface a record entered from
 * (`mobile_app` vs `dashboard`) without overloading origin. Nullable, sqlite-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_operations', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_operations', 'channel')) {
                $t->string('channel', 16)->nullable()->after('origin')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('asab_operations', function (Blueprint $t) {
            if (Schema::hasColumn('asab_operations', 'channel')) {
                $t->dropColumn('channel');
            }
        });
    }
};
