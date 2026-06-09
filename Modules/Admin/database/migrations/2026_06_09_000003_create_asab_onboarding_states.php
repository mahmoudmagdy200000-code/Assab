<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user onboarding tour state (FE completion request §2.2). Step IDs are
 * FE-defined; we only persist which were completed + skip/complete flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asab_onboarding_states')) {
            return;
        }
        Schema::create('asab_onboarding_states', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id')->primary();
            $t->uuid('user_id')->unique();
            $t->json('completed_steps')->nullable();
            $t->boolean('skipped')->default(false);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_onboarding_states');
    }
};
