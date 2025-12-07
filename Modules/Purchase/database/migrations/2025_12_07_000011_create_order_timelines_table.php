<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('order_timelines')) {
            return;
        }

        Schema::create('order_timelines', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Polymorphic relationship to support orders, returns, variances, etc.
            $table->uuidMorphs('timelineable');

            // Event type
            $table->string('event_type');

            // Status change
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();

            // Actor information
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type')->nullable(); // BranchManager, Supplier, Admin, etc.
            $table->string('actor_name')->nullable();
            $table->string('actor_image')->nullable();
            $table->string('actor_role')->nullable();

            // Event details
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // Additional data specific to the event

            // Files/attachments
            $table->json('attachments')->nullable();

            // IP and device info for audit
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            // Indexes (uuidMorphs already creates index for timelineable_type and timelineable_id)
            $table->index('event_type');
            $table->index('actor_id');
            $table->index('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_timelines');
    }
};
