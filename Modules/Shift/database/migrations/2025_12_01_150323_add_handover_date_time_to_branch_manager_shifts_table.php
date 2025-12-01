<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // في ملف الـ migration الجديد
    public function up()
    {
        Schema::table('branch_manager_shifts', function (Blueprint $table) {
            $table->date('handover_date')->nullable()->after('handover_amount');
            $table->datetime('handover_time')->nullable()->after('handover_date');
        });
    }

    public function down()
    {
        Schema::table('branch_manager_shifts', function (Blueprint $table) {
            $table->dropColumn(['handover_date', 'handover_time']);
        });
    }
};
