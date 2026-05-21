<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_owner_report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('brand_owner_id');

            // expenses | custody
            $table->string('report_kind');
            // pdf | excel
            $table->string('format');

            $table->string('title');
            $table->string('file_path');
            $table->json('params')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('brand_owner_id');
            $table->index('report_kind');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_owner_report_exports');
    }
};
