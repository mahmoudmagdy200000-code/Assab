<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_employee_movements', function (Blueprint $table) {
            $table->uuid('reversal_of_movement_id')->nullable();
            $table->foreign('reversal_of_movement_id', 'asab_emp_mov_reversal_fk')
                ->references('id')->on('asab_employee_movements')->restrictOnDelete();
            $table->unique('reversal_of_movement_id', 'asab_emp_mov_reversal_uq');
        });
    }

    public function down(): void
    {
        Schema::table('asab_employee_movements', function (Blueprint $table) {
            $table->dropForeign('asab_emp_mov_reversal_fk');
            $table->dropUnique('asab_emp_mov_reversal_uq');
            $table->dropColumn('reversal_of_movement_id');
        });
    }
};
