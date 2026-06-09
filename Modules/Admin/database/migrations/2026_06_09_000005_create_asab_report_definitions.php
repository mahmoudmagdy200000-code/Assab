<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved custom-report definitions (FE completion request §2.4). Tenant-scoped:
 * a company only sees its own saved reports. The definition mirrors the preview
 * body (dimensions/metrics/filters/dateRange).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asab_report_definitions')) {
            return;
        }
        Schema::create('asab_report_definitions', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('created_by_id')->nullable();
            $t->string('name', 160);
            $t->string('description_ar')->nullable();
            $t->json('definition');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_report_definitions');
    }
};
