<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Auto-generated daily sessions have no creator (system-generated).
     */
    public function up(): void
    {
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->uuid('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->uuid('created_by')->nullable(false)->change();
        });
    }
};
