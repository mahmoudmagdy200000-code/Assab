<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detail tables for the deep per-role flows with no clean legacy equivalent in
 * the spec's shape: assets + expense→asset drafts (§6.3.8) and supplier portal
 * catalog (§6.6.5). asab_-prefixed, InnoDB, guarded.
 */
return new class extends Migration
{
    private function make(string $name, callable $def): void
    {
        if (Schema::hasTable($name)) {
            return;
        }
        Schema::create($name, function (Blueprint $t) use ($def) {
            $t->engine = 'InnoDB';
            $def($t);
        });
    }

    public function up(): void
    {
        $this->make('asab_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->string('public_id', 16)->unique();
            $t->string('name', 200);
            $t->string('category', 32)->nullable();
            $t->uuid('branch_id')->nullable()->index();
            $t->unsignedBigInteger('cost')->default(0);
            $t->unsignedBigInteger('book_value')->default(0);
            $t->integer('useful_life_months')->default(60);
            $t->string('case_type', 24)->nullable();
            $t->string('status', 24)->default('pending_branch');
            $t->string('inv_num', 64)->nullable();
            $t->uuid('submitted_by_id')->nullable();
            $t->string('custodian', 200)->nullable();
            $t->timestamp('purchased_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->make('asab_asset_drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('draft_id', 64)->unique();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('expense_op_id')->nullable();
            $t->string('inv_num', 64)->nullable();
            $t->string('vendor', 200)->nullable();
            $t->text('desc')->nullable();
            $t->unsignedBigInteger('amount')->default(0);
            $t->string('expense_branch', 200)->nullable();
            $t->timestamp('expense_date')->nullable();
            $t->string('asset_name', 200);
            $t->string('category', 32)->nullable();
            $t->integer('useful_life_months')->default(60);
            $t->json('target_branches')->nullable();
            $t->string('custodian', 200)->nullable();
            $t->integer('qty')->default(1);
            $t->text('notes')->nullable();
            $t->string('status', 16)->default('draft');
            $t->timestamp('converted_at')->nullable();
            $t->uuid('created_by_id')->nullable();
            $t->timestamps();
        });

        $this->make('asab_supplier_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('supplier_user_id')->index();
            $t->string('code', 32)->nullable();
            $t->string('name', 200);
            $t->string('unit', 16)->nullable();
            $t->unsignedBigInteger('price')->default(0);
            $t->integer('min_qty')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_supplier_items');
        Schema::dropIfExists('asab_asset_drafts');
        Schema::dropIfExists('asab_assets');
    }
};
