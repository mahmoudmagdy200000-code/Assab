<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Registration method: personal (Start Myself) or staff (Assign Staff).
     */
    public function up(): void
    {
        Schema::table('waste_damage_reports', function (Blueprint $table) {
            $table->string('assigned_to_type', 20)->default('personal')->after('created_by');
            $table->uuid('assigned_to_id')->nullable()->after('assigned_to_type');

            $table->index('assigned_to_id');

            $table->foreign('assigned_to_id')
                ->references('id')
                ->on('cashiers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waste_damage_reports', function (Blueprint $table) {
            $table->dropForeign(['assigned_to_id']);
            $table->dropColumn(['assigned_to_type', 'assigned_to_id']);
        });
    }
};
