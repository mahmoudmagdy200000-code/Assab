// Modules/Aggregator/Database/Migrations/2024_01_01_000002_create_branch_aggregators_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_aggregators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('aggregator_id')->constrained('aggregators')->onDelete('cascade');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            // Unique constraint to prevent duplicates
            $table->unique(['branch_id', 'aggregator_id']);

            // Indexes
            $table->index(['branch_id', 'is_enabled']);
            $table->index(['aggregator_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_aggregators');
    }
};

