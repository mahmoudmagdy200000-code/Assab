<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permission-matrix version history (FE completion request §2.3). Each save of
 * the global matrix captures an immutable snapshot so it can be reviewed and
 * restored. company_id is nullable to match the global (platform) matrix.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asab_permission_snapshots')) {
            return;
        }
        Schema::create('asab_permission_snapshots', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id')->primary();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('saved_by_id')->nullable();
            $t->string('saved_by_name')->nullable();
            $t->json('snapshot'); // full matrix payload (matrix/roles/legend)
            $t->integer('changes_count')->default(0);
            $t->string('summary_ar')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_permission_snapshots');
    }
};
