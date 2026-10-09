<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asab_command_idempotency_owners', function (Blueprint $table): void {
            $table->char('key_hash', 64)->primary();
            $table->string('actor_type', 191);
            $table->string('actor_id', 64);
            $table->timestamps();
        });

        Schema::create('asab_command_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('identity_hash', 64)->unique('asab_cmd_idem_identity_unique');
            $table->string('idempotency_key', 100);
            $table->char('payload_hash', 64);
            $table->string('actor_type', 191);
            $table->string('actor_id', 64);
            $table->json('company_scope')->nullable();
            $table->string('branch_scope', 64)->nullable();
            $table->string('http_method', 8);
            $table->string('command', 255);
            $table->text('resource_scope')->nullable();
            $table->string('status', 16)->default('processing');
            $table->timestamp('reservation_expires_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('response_expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'response_expires_at'], 'asab_cmd_idem_response_expiry_index');
            $table->index(['actor_type', 'actor_id'], 'asab_cmd_idem_actor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_command_idempotency_keys');
        Schema::dropIfExists('asab_command_idempotency_owners');
    }
};
