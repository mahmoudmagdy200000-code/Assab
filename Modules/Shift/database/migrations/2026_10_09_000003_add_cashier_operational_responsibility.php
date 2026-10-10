<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->uuid('operational_chain_id')->nullable()->index();
            $table->timestamp('operational_ended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropIndex(['operational_chain_id']);
            $table->dropColumn(['operational_chain_id', 'operational_ended_at']);
        });
    }
};
