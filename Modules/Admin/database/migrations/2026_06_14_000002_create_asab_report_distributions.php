<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records each admin report send (BACKEND_API_SPEC.md §1.7a). One row per
 * (reportKey, restaurant) recipient so the distribution is auditable. Money-free.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asab_report_distributions')) {
            return;
        }
        Schema::create('asab_report_distributions', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id')->primary();
            $t->uuid('company_id')->nullable()->index();
            $t->string('report_key', 80);
            $t->uuid('restaurant_id')->nullable()->index();
            $t->json('channels')->nullable();
            $t->string('period_from')->nullable();
            $t->string('period_to')->nullable();
            $t->boolean('sent')->default(true);
            $t->timestamp('sent_at')->nullable();
            $t->uuid('sent_by_id')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'report_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_report_distributions');
    }
};
