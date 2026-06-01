<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting tables for the deep accountant flows (BACKEND_API_SPEC.md §6.3.5–6.3.11):
 * brand inventory catalog + per-branch daily lists, shifts, employee account
 * movements, cash custody + transactions. asab_-prefixed, InnoDB, guarded.
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
        $this->make('asab_inventory_catalog', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('brand_id')->index();
            $t->string('name', 200);
            $t->string('category', 80)->nullable();
            $t->string('unit', 16)->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        $this->make('asab_branch_inventory_lists', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('branch_id')->index();
            $t->uuid('catalog_item_id')->index();
            $t->boolean('is_flagged')->default(false);
            $t->uuid('added_by_id')->nullable();
            $t->timestamps();
            $t->unique(['branch_id', 'catalog_item_id']);
        });

        $this->make('asab_shifts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('branch_id')->nullable()->index();
            $t->uuid('supervisor_user_id')->nullable();
            $t->string('supervisor_name', 200)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->string('status', 16)->default('active'); // active|late|closed
            $t->integer('orders_count')->default(0);
            $t->unsignedBigInteger('sales_amount')->default(0);
            $t->unsignedBigInteger('cash_expected')->nullable();
            $t->unsignedBigInteger('cash_actual')->nullable();
            $t->bigInteger('variance')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->make('asab_brand_shift_configs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('brand_id')->index();
            $t->integer('num_shifts')->default(1);
            $t->integer('duration_hours')->default(8);
            $t->string('first_shift_start', 8)->default('08:00');
            $t->json('shifts')->nullable();
            $t->timestamps();
        });

        $this->make('asab_employee_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('employee_id')->index();
            $t->timestamp('movement_date')->nullable();
            $t->string('description', 255);
            $t->string('movement_type', 8); // credit|debit
            $t->bigInteger('amount');
            $t->uuid('ref_operation_id')->nullable();
            $t->uuid('created_by_id')->nullable();
            $t->timestamps();
        });

        $this->make('asab_cash_custody', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('branch_id')->nullable()->index();
            $t->uuid('custodian_user_id')->nullable();
            $t->string('custodian_name', 200);
            $t->unsignedBigInteger('amount')->default(0);
            $t->unsignedBigInteger('used')->default(0);
            $t->integer('days_since_settlement')->default(0);
            $t->timestamp('last_settlement_at')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        $this->make('asab_cash_transactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('custody_id')->index();
            $t->timestamp('txn_date')->nullable();
            $t->string('description', 255);
            $t->string('txn_type', 8); // credit|debit
            $t->bigInteger('amount');
            $t->string('status', 16)->default('approved');
            $t->uuid('created_by_id')->nullable();
            $t->timestamps();
        });

        $this->make('asab_settlement_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('custody_id')->index();
            $t->uuid('requested_by_id')->nullable();
            $t->string('status', 16)->default('pending');
            $t->timestamp('requested_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        $this->make('asab_upload_status', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('owner_type', 16); // brand|restaurant|branch
            $t->uuid('owner_id')->index();
            $t->string('upload_type', 32); // sales-items|raw-materials|suppliers|employees|fixed-assets
            $t->integer('uploaded_count')->default(0);
            $t->timestamp('uploaded_at')->nullable();
            $t->uuid('uploaded_by_id')->nullable();
            $t->timestamps();
            $t->unique(['owner_type', 'owner_id', 'upload_type']);
        });
    }

    public function down(): void
    {
        foreach ([
            'asab_upload_status', 'asab_settlement_requests', 'asab_cash_transactions', 'asab_cash_custody',
            'asab_employee_movements', 'asab_brand_shift_configs', 'asab_shifts',
            'asab_branch_inventory_lists', 'asab_inventory_catalog',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
