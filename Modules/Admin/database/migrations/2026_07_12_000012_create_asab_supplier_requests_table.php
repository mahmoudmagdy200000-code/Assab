<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T12.6 — a branch «طلب مورد جديد» is now persisted (was fire-and-forget), so
 * procurement can list/approve it and the branch can see «قيد المراجعة»/«معتمد»
 * chips. Approval promotes the row into an asab_suppliers record (T11 bridge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asab_supplier_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('branch_id')->nullable()->index();
            $t->string('name', 200);
            $t->string('category', 80)->nullable();
            $t->string('contact_phone', 32)->nullable();
            $t->text('reason')->nullable();
            $t->string('status', 24)->default('pending_review')->index();
            $t->uuid('requested_by_id')->nullable();
            $t->uuid('supplier_id')->nullable(); // set when approved → asab_suppliers.id
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_supplier_requests');
    }
};
