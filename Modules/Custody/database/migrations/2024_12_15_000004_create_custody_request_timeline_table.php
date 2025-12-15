<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_request_timeline', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('custody_request_id')->constrained('custody_requests')->cascadeOnDelete();

            $table->enum('stage', ['Submit Case', 'View Case', 'Approve Case', 'Reject Case']);
            $table->enum('status', ['Submitted', 'Viewed', 'Approved', 'Rejected']);

            // Actor (polymorphic - can be BranchManager or BrandOwner)
            $table->uuid('actor_id');
            $table->string('actor_type'); // 'branch_manager' or 'brand_owner'
            $table->string('actor_name');
            $table->string('actor_profile_image')->nullable();

            $table->timestamp('action_date');
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('custody_request_id');
            $table->index(['actor_id', 'actor_type']);
            $table->index('action_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_request_timeline');
    }
};
