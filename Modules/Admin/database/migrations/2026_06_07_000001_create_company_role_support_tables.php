<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting tables for the Company Dashboard role surfaces
 * (COMPANY_DASHBOARD_API_SPEC.md §5.2–§5.5): supplier ratings, personal
 * reminders (head + accountant), and procurement item price history.
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
        $this->make('asab_supplier_ratings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('supplier_id')->index();
            $t->uuid('rater_user_id')->nullable();
            $t->integer('rating'); // 1-5
            $t->text('comment')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $this->make('asab_personal_reminders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('user_id')->index();
            $t->string('role_key', 32);
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->string('type', 16)->default('finance'); // urgent|report|finance|team (head) ; priority used by accountant
            $t->string('priority', 8)->default('medium'); // high|medium|low
            $t->timestamp('due_at')->nullable();
            $t->boolean('done')->default(false);
            $t->timestamps();
        });

        $this->make('asab_procurement_item_prices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('item_id')->index();
            $t->uuid('supplier_id')->nullable();
            $t->string('supplier_name', 200)->nullable();
            $t->bigInteger('price'); // halalas
            $t->timestamp('recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['asab_procurement_item_prices', 'asab_personal_reminders', 'asab_supplier_ratings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
