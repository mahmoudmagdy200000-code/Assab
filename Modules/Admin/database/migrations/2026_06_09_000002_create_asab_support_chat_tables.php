<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live support chat (FE completion request §2.1). A session belongs to one
 * company user; messages are user/agent turns. Realtime is broadcast on the
 * private `chat.session.{sessionId}` channel.
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
        $this->make('asab_support_chat_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id')->index();
            $t->uuid('user_id')->index();
            $t->uuid('agent_user_id')->nullable();
            $t->string('agent_name')->nullable();
            $t->string('status', 16)->default('queued'); // queued|active|closed
            $t->integer('queue_position')->nullable();
            $t->integer('estimated_wait_seconds')->nullable();
            $t->string('closed_by', 16)->nullable(); // user|agent|system
            $t->string('close_reason')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });

        $this->make('asab_support_chat_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('session_id')->index();
            $t->string('author_type', 16); // user|agent
            $t->uuid('author_id')->nullable();
            $t->text('text');
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_support_chat_messages');
        Schema::dropIfExists('asab_support_chat_sessions');
    }
};
