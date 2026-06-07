<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting per-user/company tables (MISSING_Dashboard §11): saved filter
 * presets, persistent table column preferences, and a reminder-broadcast log.
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
        $this->make('asab_saved_filters', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('name', 120);
            $t->string('page', 80)->index();
            $t->json('params')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $this->make('asab_table_prefs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('table_key', 80); // 'table' is a reserved word
            $t->json('visible_columns')->nullable();
            $t->json('column_order')->nullable();
            $t->integer('page_size')->default(20);
            $t->timestamps();
            $t->unique(['user_id', 'table_key']);
        });

        $this->make('asab_reminder_broadcasts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('sender_user_id')->nullable();
            $t->text('message_ar');
            $t->text('message_en')->nullable();
            $t->string('audience', 40);
            $t->json('branch_ids')->nullable();
            $t->integer('sent_count')->default(0);
            $t->integer('failed_count')->default(0);
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['asab_reminder_broadcasts', 'asab_table_prefs', 'asab_saved_filters'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
