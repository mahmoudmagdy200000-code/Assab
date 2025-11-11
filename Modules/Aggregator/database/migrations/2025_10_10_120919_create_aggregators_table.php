<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aggregators', function (Blueprint $table) {
             $table->uuid('id');
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->string('logo')->nullable();
            $table->text('description')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->decimal('commission_rate', 5, 2)->default(0); // Percentage
            $table->string('payment_terms')->nullable(); // e.g., "Weekly", "Monthly"
            $table->boolean('is_active')->default(true);
            $table->enum('integration_type', ['manual', 'api', 'webhook'])->default('manual');
            $table->text('api_key')->nullable();
            $table->string('api_endpoint')->nullable();
            $table->string('webhook_url')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('name');
            $table->index('code');
            $table->index('is_active');
            $table->index('integration_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aggregators');
    }
};

