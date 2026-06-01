<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Dashboard surface (COMPANY_DASHBOARD_API_SPEC.md §3): company-level
 * plans/subscriptions, billing, modules toggle, company users + invitations,
 * support tickets, and company settings/preferences. Distinct from the main
 * spec's per-restaurant asab_subscriptions. Money = integer halalas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Plans & subscriptions (company-level) ---
        $this->create('asab_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 32)->unique();          // basic|professional|enterprise
            $t->string('name_ar', 80);
            $t->string('name_en', 80);
            $t->bigInteger('price_monthly')->nullable(); // halalas; null = custom
            $t->bigInteger('price_annual')->nullable();
            $t->integer('annual_discount_pct')->default(17);
            $t->integer('max_branches')->nullable();      // null = unlimited
            $t->integer('max_users')->nullable();
            $t->integer('max_brands')->nullable();
            $t->integer('max_restaurants')->nullable();
            $t->integer('storage_gb')->nullable();
            $t->json('modules_included');
            $t->boolean('has_account_manager')->default(false);
            $t->boolean('has_advanced_reports')->default(false);
            $t->boolean('has_sla')->default(false);
            $t->integer('sla_uptime_pct')->nullable();
            $t->boolean('has_open_api')->default(false);
            $t->integer('sort_order')->default(0);
            $t->string('status', 16)->default('active');
            $t->timestamps();
        });

        $this->create('asab_plan_features', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('plan_id')->index();
            $t->string('label_ar', 120);
            $t->string('label_en', 120);
            $t->integer('sort_order')->default(0);
            $t->boolean('is_highlighted')->default(false);
        });

        $this->create('asab_company_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->unique();          // ONE per company
            $t->uuid('plan_id')->index();
            $t->string('status', 16);                   // trial|active|past_due|grace_period|suspended|cancelled|expired
            $t->string('billing_cycle', 16);            // monthly|annual
            $t->timestamp('current_period_start');
            $t->timestamp('current_period_end');
            $t->timestamp('trial_ends_at')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->timestamp('cancelled_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamp('start_date');
            $t->integer('days_remaining')->nullable();
            $t->boolean('auto_renew')->default(true);
            $t->uuid('default_payment_method_id')->nullable();
            $t->string('contract_number', 64)->nullable();
            $t->timestamps();
        });

        $this->create('asab_subscription_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('subscription_id')->index();
            $t->string('change_type', 16);              // upgrade|downgrade|cycle_change|cancel|reactivate|trial_to_paid|renewal
            $t->uuid('from_plan_id')->nullable();
            $t->uuid('to_plan_id')->nullable();
            $t->string('from_billing_cycle', 16)->nullable();
            $t->string('to_billing_cycle', 16)->nullable();
            $t->bigInteger('proration_amount')->nullable();
            $t->timestamp('effective_at');
            $t->uuid('invoice_id')->nullable();
            $t->uuid('initiated_by_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $this->create('asab_subscription_quota_usage', function (Blueprint $t) {
            $t->uuid('company_id')->primary();
            $t->integer('used_branches')->default(0);
            $t->integer('used_users')->default(0);
            $t->integer('used_brands')->default(0);
            $t->integer('used_restaurants')->default(0);
            $t->bigInteger('used_storage_bytes')->default(0);
            $t->timestamp('computed_at')->nullable();
        });

        // --- Billing ---
        $this->create('asab_billing_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('subscription_id')->index();
            $t->string('public_id', 24)->unique();      // INV-2025-012
            $t->timestamp('issue_date');
            $t->timestamp('due_date');
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->bigInteger('subtotal');
            $t->integer('vat_rate')->default(15);
            $t->bigInteger('vat_amount');
            $t->bigInteger('discount')->default(0);
            $t->bigInteger('total');
            $t->bigInteger('amount_paid')->default(0);
            $t->bigInteger('amount_due');
            $t->string('status', 16);                   // draft|open|paid|partially_paid|overdue|void|refunded
            $t->uuid('payment_method_id')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('pdf_storage_key', 500)->nullable();
            $t->json('billing_address_snapshot')->nullable();
            $t->text('notes')->nullable();
            $t->string('currency', 3)->default('SAR');
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        $this->create('asab_billing_invoice_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('invoice_id')->index();
            $t->string('description', 255);
            $t->integer('quantity')->default(1);
            $t->bigInteger('unit_price');
            $t->bigInteger('amount');
            $t->string('line_type', 16);                // subscription|proration|overage|addon|credit|discount
            $t->integer('sort_order')->default(0);
        });

        $this->create('asab_payment_methods', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->string('type', 16);                     // card|mada|bank_transfer|apple_pay
            $t->string('brand', 16)->nullable();
            $t->string('last4', 4)->nullable();
            $t->integer('exp_month')->nullable();
            $t->integer('exp_year')->nullable();
            $t->string('holder_name', 200)->nullable();
            $t->string('provider_token', 255);
            $t->string('provider_name', 32);
            $t->boolean('is_default')->default(false);
            $t->string('status', 16)->default('active');
            $t->uuid('added_by_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->create('asab_billing_addresses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->string('legal_name', 200);
            $t->string('tax_id', 32)->nullable();
            $t->string('cr_number', 32)->nullable();
            $t->string('address_line1', 255);
            $t->string('address_line2', 255)->nullable();
            $t->string('city', 80);
            $t->string('region', 80)->nullable();
            $t->string('postal_code', 16)->nullable();
            $t->string('country', 2)->default('SA');
            $t->string('contact_email', 255)->nullable();
            $t->string('contact_phone', 32)->nullable();
            $t->boolean('is_default')->default(true);
            $t->timestamps();
        });

        $this->create('asab_payment_transactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('invoice_id')->index();
            $t->uuid('payment_method_id')->nullable();
            $t->bigInteger('amount');
            $t->string('currency', 3)->default('SAR');
            $t->string('status', 16);                   // pending|succeeded|failed|refunded|chargeback
            $t->string('provider_txn_id', 128)->nullable();
            $t->json('provider_response')->nullable();
            $t->string('failure_code', 32)->nullable();
            $t->text('failure_message')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $this->create('asab_webhook_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('provider', 32);
            $t->string('event_id', 128)->unique();
            $t->string('event_type', 64);
            $t->json('payload');
            $t->string('signature', 512)->nullable();
            $t->boolean('signature_verified')->default(false);
            $t->timestamp('processed_at')->nullable();
            $t->text('processing_error')->nullable();
            $t->timestamp('received_at')->nullable();
        });

        // --- Modules toggle ---
        $this->create('asab_company_modules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->string('module_key', 32);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_in_plan')->default(true);
            $t->uuid('toggled_by_id')->nullable();
            $t->timestamp('toggled_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'module_key']);
        });

        // --- Company users & invitations ---
        $this->create('asab_company_users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('user_id')->index();
            $t->string('role_key', 32);
            $t->uuid('brand_id')->nullable();
            $t->uuid('branch_id')->nullable();
            $t->string('status', 16)->default('active'); // invited|active|inactive|suspended
            $t->timestamp('last_seen_at')->nullable();
            $t->uuid('invited_by_id')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'user_id']);
        });

        $this->create('asab_company_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->string('email', 191);
            $t->string('name', 200)->nullable();
            $t->string('role_key', 32);
            $t->uuid('brand_id')->nullable();
            $t->uuid('branch_id')->nullable();
            $t->string('token', 128)->unique();
            $t->string('status', 16)->default('pending'); // pending|accepted|revoked|expired
            $t->uuid('invited_by_id')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // --- Support ---
        $this->create('asab_support_tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('public_id', 16)->unique();      // TCK-001
            $t->uuid('company_id')->index();
            $t->uuid('opened_by_id');
            $t->string('category', 32);
            $t->string('subject', 200);
            $t->text('body');
            $t->string('priority', 8)->default('normal');
            $t->string('status', 16)->default('open');
            $t->uuid('assigned_to_id')->nullable();
            $t->timestamp('first_response_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });

        $this->create('asab_ticket_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('ticket_id')->index();
            $t->uuid('author_id');
            $t->string('author_type', 16);              // customer|support_agent
            $t->text('body');
            $t->boolean('is_internal')->default(false);
            $t->timestamp('created_at')->nullable();
        });

        $this->create('asab_ticket_attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('ticket_id')->index();
            $t->uuid('message_id')->nullable();
            $t->string('filename', 255);
            $t->string('mime_type', 80);
            $t->integer('size');
            $t->string('storage_key', 500);
            $t->uuid('uploaded_by_id')->nullable();
            $t->timestamp('uploaded_at')->nullable();
        });

        $this->create('asab_support_channels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 16)->unique();            // chat|phone|email
            $t->string('label_ar', 80);
            $t->string('label_en', 80);
            $t->string('value', 120);
            $t->string('hours_ar', 120)->nullable();
            $t->string('hours_en', 120)->nullable();
            $t->boolean('is_available')->default(true);
            $t->string('icon', 8)->nullable();
            $t->integer('sort_order')->default(0);
        });

        // --- Company settings & preferences ---
        $this->create('asab_company_settings', function (Blueprint $t) {
            $t->uuid('company_id')->primary();
            $t->string('legal_name', 200);
            $t->string('display_name', 200)->nullable();
            $t->string('logo_emoji', 16)->nullable();
            $t->string('logo_url', 500)->nullable();
            $t->string('primary_city', 80)->nullable();
            $t->string('cr_number', 32)->nullable();
            $t->string('tax_id', 32)->nullable();
            $t->string('email', 255)->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('website', 255)->nullable();
            $t->text('address_line')->nullable();
            $t->string('default_currency', 3)->default('SAR');
            $t->string('default_timezone', 64)->default('Asia/Riyadh');
            $t->string('default_language', 2)->default('ar');
            $t->integer('vat_percentage')->default(15);
            $t->string('fiscal_year_start', 8)->default('01-01');
            $t->string('brand_color', 16)->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->uuid('updated_by_id')->nullable();
        });

        $this->create('asab_company_preferences', function (Blueprint $t) {
            $t->uuid('company_id')->primary();
            $t->boolean('notify_on_approval')->default(true);
            $t->boolean('notify_on_rejection')->default(true);
            $t->boolean('notify_on_low_stock')->default(true);
            $t->boolean('notify_on_sub_expiring')->default(true);
            $t->boolean('auto_reminder_enabled')->default(true);
            $t->string('reminder_trigger_hour', 8)->default('22:00');
            $t->integer('reminder_repeat_hours')->default(3);
            $t->json('pos_integrations')->nullable();
            $t->json('delivery_app_integrations')->nullable();
        });
    }

    private function create(string $table, \Closure $definition): void
    {
        if (Schema::hasTable($table)) {
            return;
        }
        Schema::create($table, function (Blueprint $t) use ($definition) {
            $t->engine = 'InnoDB';
            $definition($t);
        });
    }

    public function down(): void
    {
        foreach ([
            'asab_company_preferences', 'asab_company_settings', 'asab_support_channels',
            'asab_ticket_attachments', 'asab_ticket_messages', 'asab_support_tickets',
            'asab_company_invitations', 'asab_company_users', 'asab_company_modules',
            'asab_webhook_events', 'asab_payment_transactions', 'asab_billing_addresses',
            'asab_payment_methods', 'asab_billing_invoice_lines', 'asab_billing_invoices',
            'asab_subscription_quota_usage', 'asab_subscription_changes',
            'asab_company_subscriptions', 'asab_plan_features', 'asab_plans',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
