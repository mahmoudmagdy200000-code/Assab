<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shift_history', function (Blueprint $table) {
            $table->uuid('performed_by')->change();
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shift_history', function (Blueprint $table) {
            $table->string('performed_by')->change();
        });
    }
};
