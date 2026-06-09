<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production-readiness / enterprise tables (FE completion request §3):
 * 2FA (cols on asab_users), SSO config, API keys, GDPR export + deletion,
 * tenant webhooks + deliveries, and background-job monitoring. Portable types
 * so the SQLite test driver runs them unchanged. Secrets are encrypted at the
 * model layer, never stored in plain text.
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

    private function add(string $table, string $column, callable $def): void
    {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $t) => $def($t));
        }
    }

    public function up(): void
    {
        // 3.1 — 2FA columns on the unified user.
        $this->add('asab_users', 'two_factor_method', function (Blueprint $t) {
            $t->string('two_factor_method', 8)->nullable()->after('password');   // totp|sms|null
            $t->text('two_factor_secret')->nullable()->after('two_factor_method'); // encrypted
            $t->text('two_factor_backup_codes')->nullable()->after('two_factor_secret'); // encrypted json
            $t->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_backup_codes');
        });

        // 3.2 — SSO configuration (one per company).
        $this->make('asab_company_sso', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->unique();
            $t->string('provider', 8);                  // saml|oidc
            $t->boolean('enabled')->default(false);
            $t->string('metadata_url', 500)->nullable();
            $t->text('metadata')->nullable();           // raw SAML metadata xml
            $t->string('entity_id', 255)->nullable();
            $t->text('x509cert')->nullable();
            $t->string('oidc_issuer', 500)->nullable();
            $t->string('oidc_client_id', 255)->nullable();
            $t->text('oidc_client_secret')->nullable(); // encrypted
            $t->string('default_role', 32)->default('accountant');
            $t->timestamps();
        });

        // 3.3 — API keys for 3rd-party integrations.
        $this->make('asab_api_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->string('name', 120);
            $t->string('prefix', 24)->index();          // asab_live_ABC123
            $t->string('key_hash', 128);                // sha256 of full key
            $t->json('scopes')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->uuid('created_by_id')->nullable();
            $t->timestamps();
        });

        // 3.4 — GDPR / PDPL data export jobs + account-deletion requests.
        $this->make('asab_data_export_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->uuid('company_id')->nullable();
            $t->string('status', 16)->default('queued'); // queued|processing|ready|failed
            $t->string('storage_key', 500)->nullable();
            $t->string('download_url', 1000)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
        });

        $this->make('asab_account_deletion_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('confirm_email', 191);
            $t->text('reason')->nullable();
            $t->string('status', 16)->default('scheduled'); // scheduled|cancelled|completed
            $t->timestamp('scheduled_for');
            $t->timestamps();
        });

        // 3.5 — Tenant webhooks + delivery log.
        $this->make('asab_webhooks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->string('url', 1000);
            $t->json('events');
            $t->text('secret');                         // encrypted at model layer (needed to HMAC-sign deliveries)
            $t->string('secret_prefix', 24);
            $t->boolean('is_active')->default(true);
            $t->string('description', 255)->nullable();
            $t->timestamp('last_triggered_at')->nullable();
            $t->integer('failure_count')->default(0);
            $t->uuid('created_by_id')->nullable();
            $t->timestamps();
        });

        $this->make('asab_webhook_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('webhook_id')->index();
            $t->string('event', 64);
            $t->json('payload')->nullable();
            $t->integer('status_code')->nullable();
            $t->text('response')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('attempted_at')->nullable();
            $t->timestamps();
        });

        // 3.6 — Background-job run monitoring.
        $this->make('asab_job_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 32)->index();            // erp.batch|asset.import|report.export|users.import|brand.upload
            $t->string('status', 16)->index();          // queued|running|done|failed|retrying|cancelled
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('branch_id')->nullable();
            $t->uuid('triggered_by_id')->nullable();
            $t->string('triggered_by_name')->nullable();
            $t->unsignedTinyInteger('progress_pct')->default(0);
            $t->integer('attempt_count')->default(0);
            $t->text('last_error')->nullable();
            $t->string('ref_type', 32)->nullable();
            $t->uuid('ref_id')->nullable();
            $t->timestamp('queued_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['asab_job_runs', 'asab_webhook_deliveries', 'asab_webhooks', 'asab_account_deletion_requests',
            'asab_data_export_jobs', 'asab_api_keys', 'asab_company_sso'] as $t) {
            Schema::dropIfExists($t);
        }
        foreach (['two_factor_method', 'two_factor_secret', 'two_factor_backup_codes', 'two_factor_confirmed_at'] as $col) {
            if (Schema::hasColumn('asab_users', $col)) {
                Schema::table('asab_users', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
