<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('custody_requests', function (Blueprint $table) {
                $table->text('purpose')->nullable()->change();
            });

            return;
        }

        DB::statement('ALTER TABLE `custody_requests` MODIFY `purpose` TEXT NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('custody_requests', function (Blueprint $table) {
                $table->text('purpose')->nullable(false)->change();
            });

            return;
        }

        DB::statement('ALTER TABLE `custody_requests` MODIFY `purpose` TEXT NOT NULL');
    }
};
