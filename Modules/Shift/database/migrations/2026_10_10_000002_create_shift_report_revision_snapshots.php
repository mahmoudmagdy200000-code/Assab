<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_report_revision_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_revision_id')->unique()->constrained('shift_report_revisions')->restrictOnDelete();
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('snapshot_data');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_report_revision_snapshots');
    }
};
