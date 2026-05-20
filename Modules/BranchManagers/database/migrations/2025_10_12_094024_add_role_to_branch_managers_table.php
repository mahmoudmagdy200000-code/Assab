<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branch_managers', function (Blueprint $table) {
            if (! Schema::hasColumn('branch_managers', 'role')) {
                $table->string('role')->default('branch_manager')->after('password');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_managers', function (Blueprint $table) {
            if (Schema::hasColumn('branch_managers', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};
