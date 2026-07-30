<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_assets')) {
            return;
        }

        Schema::table('asab_assets', function (Blueprint $table) {
            // Stamped when the branch manager confirms receipt on mobile
            // (meeting 2026-07-30: «تم الاستلام» must be recorded).
            if (! Schema::hasColumn('asab_assets', 'received_by_id')) {
                $table->uuid('received_by_id')->nullable();
            }
            if (! Schema::hasColumn('asab_assets', 'received_at')) {
                $table->timestamp('received_at')->nullable();
            }
            if (! Schema::hasColumn('asab_assets', 'received_note')) {
                $table->string('received_note')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('asab_assets', function (Blueprint $table) {
            $table->dropColumn(['received_by_id', 'received_at', 'received_note']);
        });
    }
};
