<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            // Existing and new requests start on review cycle 1. Resubmission
            // increments this value so cached decisions cannot affect a new cycle.
            $table->unsignedInteger('review_revision')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn('review_revision');
        });
    }
};
