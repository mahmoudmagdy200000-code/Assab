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
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->date('inventory_date')->nullable()->change();
            $table->timestamp('start_time')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->date('inventory_date')->nullable(false)->change();
            $table->timestamp('start_time')->nullable(false)->change();
        });
    }
};
