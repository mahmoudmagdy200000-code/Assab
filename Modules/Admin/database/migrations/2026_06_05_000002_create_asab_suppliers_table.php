<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand-scoped supplier catalog managed by Admin/Procurement (BACKEND_API_SPEC.md §3.4).
 * Separate from the legacy Modules/Supplier auth accounts (which need email/password).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asab_suppliers')) {
            return;
        }
        Schema::create('asab_suppliers', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('brand_id')->nullable()->index();
            $t->string('name', 200);
            $t->string('category', 80)->nullable();
            $t->string('contact_name', 200)->nullable();
            $t->string('contact_phone', 32)->nullable();
            $t->string('contact_email', 191)->nullable();
            $t->string('commercial_reg', 32)->nullable();
            $t->string('payment_terms', 80)->nullable();
            $t->uuid('user_id')->nullable();
            $t->integer('rating')->default(0);
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_suppliers');
    }
};
