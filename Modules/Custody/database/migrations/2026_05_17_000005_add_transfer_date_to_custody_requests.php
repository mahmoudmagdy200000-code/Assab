<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custody_requests', function (Blueprint $table) {
            $table->timestamp('transfer_date')->nullable()->after('handover_date');
            $table->string('recipient_employee_id')->nullable()->after('created_by_brand_owner_id');
            $table->index('recipient_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('custody_requests', function (Blueprint $table) {
            $table->dropIndex(['recipient_employee_id']);
            $table->dropColumn(['transfer_date', 'recipient_employee_id']);
        });
    }
};
