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
        Schema::table('grouped_invoices', function (Blueprint $table) {
            $table->foreignUuid('default_supplier_id')->nullable()->constrained('suppliers')->after('due_date');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grouped_invoices', function (Blueprint $table) {});
    }
};
