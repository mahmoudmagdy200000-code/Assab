<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_report_correction_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('report_revision_id')->index();
            $table->string('event_type', 64);
            $table->string('recipient_type', 64);
            $table->string('recipient_id', 64);
            $table->string('status', 32)->default('pending')->index();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['report_revision_id', 'event_type', 'recipient_type', 'recipient_id'],
                'srcn_rev_ev_recip_uniq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_report_correction_notifications');
    }
};
