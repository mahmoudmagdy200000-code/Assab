<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert user_settings.userable_id from BIGINT UNSIGNED to UUID.
     *
     * The create migration originally used $table->morphs(), which makes
     * userable_id a bigint. BranchManager & Cashier use UUID primary keys,
     * so inserts failed with "Data truncated for column 'userable_id'".
     */
    public function up(): void
    {
        // SQLite (test DB) builds the corrected schema from the create migration.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Existing rows had UUIDs truncated into the bigint column — unrecoverable.
        // user_settings holds only regenerable default preferences.
        DB::table('user_settings')->truncate();

        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropUnique('userable_unique');
            $table->dropIndex(['userable_id']);
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->uuid('userable_id')->change();
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->unique(['userable_id', 'userable_type'], 'userable_unique');
            $table->index('userable_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::table('user_settings')->truncate();

        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropUnique('userable_unique');
            $table->dropIndex(['userable_id']);
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('userable_id')->change();
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->unique(['userable_id', 'userable_type'], 'userable_unique');
            $table->index('userable_id');
        });
    }
};
