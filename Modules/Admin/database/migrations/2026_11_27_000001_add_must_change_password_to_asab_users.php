<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asab_users', 'must_change_password')) {
            Schema::table('asab_users', function (Blueprint $table) {
                $table->boolean('must_change_password')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('asab_users', 'must_change_password')) {
            Schema::table('asab_users', function (Blueprint $table) {
                $table->dropColumn('must_change_password');
            });
        }
    }
};
