<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASAB SaaS layer — REUSE approach.
 *
 * Adds ONLY the genuinely-new concepts that have no equivalent in the existing
 * modules (Branch, Inventory, FixedAssets, Supplier, Purchase, Shift, Custody).
 * Everything is asab_-prefixed (no name/functionality collision), InnoDB, and
 * each create is guarded by hasTable so the migration is safe to re-run.
 *
 * The approval pipeline (asab_operations) links to existing module records via
 * source_module + source_id, and carries a spec-shaped `payload` for data that
 * the legacy tables don't store in the spec's shape.
 */
return new class extends Migration
{
    private function make(string $name, callable $definition): void
    {
        if (Schema::hasTable($name)) {
            return;
        }
        Schema::create($name, function (Blueprint $table) use ($definition) {
            $table->engine = 'InnoDB';
            $definition($table);
        });
    }

    public function up(): void
    {
        // ---- Tenancy & org ----
        $this->make('asab_companies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 200);
            $t->string('logo', 255)->nullable();
            $t->string('contact_name', 200)->nullable();
            $t->string('contact_email', 191)->nullable();
            $t->string('contact_phone', 32)->nullable();
            $t->string('city', 80)->nullable();
            $t->string('plan', 32)->default('Basic');
            $t->string('status', 16)->default('active');
            $t->integer('max_branches')->default(5);
            $t->integer('max_users')->default(15);
            $t->unsignedBigInteger('monthly_revenue')->default(0);
            $t->timestamp('start_date')->nullable();
            $t->timestamp('next_billing')->nullable();
            $t->json('modules')->nullable();
            $t->string('admin_email', 191)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('status');
        });

        $this->make('asab_brands', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->string('name', 120);
            $t->string('abbr', 8)->nullable();
            $t->string('color', 16)->nullable();
            $t->string('owner', 200)->nullable();
            $t->string('owner_email', 191)->nullable();
            $t->string('plan', 32)->nullable();
            $t->string('sub_status', 16)->nullable();
            $t->timestamp('expires')->nullable();
            $t->integer('days_left')->nullable();
            $t->json('modules')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        $this->make('asab_restaurants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('brand_id')->index();
            $t->uuid('company_id')->index();
            $t->string('name', 200);
            $t->string('city', 80)->nullable();
            $t->integer('accountant_count')->default(0);
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        $this->make('asab_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('brand_id')->nullable();
            $t->uuid('restaurant_id')->nullable();
            $t->string('plan', 32);
            $t->string('status', 16);
            $t->timestamp('expires_at')->nullable();
            $t->integer('days_left')->nullable();
            $t->unsignedBigInteger('monthly_price')->default(0);
            $t->boolean('auto_renew')->default(false);
            $t->boolean('reminder_enabled')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        // ---- Identity & RBAC ----
        $this->make('asab_users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->nullable()->index();
            $t->string('name', 200);
            $t->string('email', 191)->unique();
            $t->string('phone', 32)->nullable();
            $t->string('password', 255);
            $t->string('avatar', 16)->nullable();
            $t->string('status', 16)->default('active');
            $t->uuid('reports_to_id')->nullable();
            $t->string('default_page', 64)->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->make('asab_roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 32)->unique();
            $t->string('name_ar', 80);
            $t->string('name_en', 80);
            $t->timestamps();
        });

        $this->make('asab_user_roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('role_key', 32);
            $t->string('scope', 16)->default('all');
            $t->json('brand_ids')->nullable();
            $t->json('restaurant_ids')->nullable();
            $t->json('branch_ids')->nullable();
            $t->json('module_keys')->nullable();
            $t->timestamps();
        });

        $this->make('asab_permission_matrix', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->nullable()->index();
            $t->string('role_key', 32);
            $t->string('module', 64);
            $t->string('permission', 16);
            $t->uuid('updated_by_id')->nullable();
            $t->timestamps();
        });

        $this->make('asab_idempotency_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 100)->unique();
            $t->uuid('user_id')->nullable();
            $t->string('method', 8);
            $t->string('path', 255);
            $t->json('response_body')->nullable();
            $t->integer('status')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
        });

        // ---- Approval pipeline (links to existing modules) ----
        $this->make('asab_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('public_id', 16)->unique();
            $t->uuid('company_id')->index();
            $t->uuid('branch_id')->nullable()->index();
            $t->string('module_key', 16);            // sales|expenses|purchases|inventory|shifts|employees|cash|waste
            $t->string('source_module', 32)->nullable(); // legacy module name
            $t->uuid('source_id')->nullable();           // legacy record id
            $t->json('payload')->nullable();             // spec-shaped data not in legacy tables
            $t->unsignedBigInteger('amount')->default(0);
            $t->string('match', 8)->default('exact');
            $t->string('diff_note', 255)->nullable();
            $t->string('origin', 16)->default('mobile');
            $t->integer('attachment_count')->default(0);
            $t->string('status', 16)->default('pending');
            $t->string('reject_reason', 500)->nullable();
            $t->uuid('submitted_by_id')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->uuid('reviewed_by_id')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->uuid('approved_by_id')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->uuid('final_approved_by_id')->nullable();
            $t->timestamp('final_approved_at')->nullable();
            $t->uuid('rejected_by_id')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->boolean('is_conditional')->default(false);
            $t->text('conditional_note')->nullable();
            $t->boolean('is_correction')->default(false);
            $t->uuid('corrective_ref_id')->nullable();
            $t->boolean('erp_posted')->default(false);
            $t->string('erp_batch_id', 64)->nullable();
            $t->timestamp('erp_posted_at')->nullable();
            $t->timestamp('operation_date')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'module_key']);
            $t->index(['branch_id', 'status']);
        });

        $this->make('asab_approval_steps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('operation_id')->index();
            $t->string('stage_id', 16);
            $t->string('action', 200);
            $t->uuid('actor_user_id')->nullable();
            $t->string('actor_label', 200)->nullable();
            $t->text('note')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->timestamps();
        });

        // ---- Employees (no legacy equivalent) ----
        $this->make('asab_employees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('branch_id')->index();
            $t->string('emp_number', 32);
            $t->string('name', 200);
            $t->string('national_id', 32)->nullable();
            $t->string('role', 80);
            $t->unsignedBigInteger('monthly_salary')->default(0);
            $t->string('shift_type', 16)->nullable();
            $t->timestamp('hire_date')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->softDeletes();
        });

        $this->make('asab_employee_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('employee_id')->index();
            $t->timestamp('movement_date')->nullable();
            $t->string('description', 255);
            $t->string('movement_type', 8);
            $t->bigInteger('amount');
            $t->uuid('ref_operation_id')->nullable();
            $t->uuid('created_by_id')->nullable();
            $t->timestamps();
        });

        // ---- Support: audit / notifications / reminders / attachments / erp / settings ----
        $this->make('asab_audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('actor_user_id')->nullable();
            $t->string('actor_label', 200)->nullable();
            $t->string('actor_role', 32)->nullable();
            $t->string('action', 80);
            $t->string('entity_type', 32)->nullable();
            $t->uuid('entity_id')->nullable();
            $t->text('description')->nullable();
            $t->string('ip', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->timestamps();
        });

        $this->make('asab_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('type', 48);
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->string('link', 255)->nullable();
            $t->string('ref_type', 32)->nullable();
            $t->uuid('ref_id')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        $this->make('asab_notification_preferences', function (Blueprint $t) {
            $t->uuid('user_id')->primary();
            $t->boolean('email_enabled')->default(true);
            $t->boolean('sms_enabled')->default(false);
            $t->boolean('push_enabled')->default(true);
            $t->boolean('approval_enabled')->default(true);
            $t->boolean('reminder_enabled')->default(true);
            $t->boolean('subscription_enabled')->default(true);
            $t->timestamps();
        });

        $this->make('asab_reminders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->string('public_id', 16);
            $t->uuid('branch_id')->nullable();
            $t->string('report_type', 80);
            $t->string('module_key', 32);
            $t->timestamp('required_by')->nullable();
            $t->integer('days_missing')->nullable();
            $t->string('urgency', 8)->default('medium');
            $t->string('reminder_status', 16)->default('not_sent');
            $t->string('response', 80)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->text('message')->nullable();
            $t->timestamps();
        });

        $this->make('asab_auto_reminder_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->string('module', 32);
            $t->string('trigger_hour', 8);
            $t->integer('repeat_hours')->default(3);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        $this->make('asab_attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('owner_type', 32);
            $t->uuid('owner_id');
            $t->string('filename', 255);
            $t->string('mime_type', 80)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->string('storage_key', 500)->nullable();
            $t->string('public_url', 500)->nullable();
            $t->string('label', 80)->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->uuid('verified_by_id')->nullable();
            $t->uuid('uploaded_by_id')->nullable();
            $t->timestamp('uploaded_at')->nullable();
            $t->timestamps();
            $t->index(['owner_type', 'owner_id']);
        });

        $this->make('asab_erp_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('batch_id', 64)->unique();
            $t->uuid('company_id')->index();
            $t->uuid('initiated_by_id')->nullable();
            $t->integer('operation_count')->default(0);
            $t->unsignedBigInteger('total_amount')->default(0);
            $t->string('status', 16)->default('queued');
            $t->json('filters')->nullable();
            $t->integer('branch_count')->nullable();
            $t->json('erp_response')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });

        $this->make('asab_erp_batch_operations', function (Blueprint $t) {
            $t->uuid('batch_id');
            $t->uuid('operation_id');
            $t->primary(['batch_id', 'operation_id']);
        });

        $this->make('asab_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->nullable()->index();
            $t->string('group_key', 32);
            $t->json('payload');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'asab_settings', 'asab_erp_batch_operations', 'asab_erp_batches', 'asab_attachments',
            'asab_auto_reminder_rules', 'asab_reminders', 'asab_notification_preferences', 'asab_notifications',
            'asab_audit_logs', 'asab_employee_movements', 'asab_employees', 'asab_approval_steps', 'asab_operations',
            'asab_idempotency_keys', 'asab_permission_matrix', 'asab_user_roles', 'asab_roles', 'asab_users',
            'asab_subscriptions', 'asab_restaurants', 'asab_brands', 'asab_companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
