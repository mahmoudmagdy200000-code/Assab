<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('notifiable'); // User, BranchManager, Cashier, etc.
            $table->string('notification_type'); // NotificationType enum value
            $table->json('channels'); // Enabled channels (default handled in model)
            $table->string('priority_level')->default('low'); // Minimum priority to receive
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // uuidMorphs already creates index on notifiable_type and notifiable_id
            $table->index('notification_type');
        });

        // Add unique constraint with prefix to handle long class names
        // Using smaller prefixes to stay within MySQL's 1000 byte limit
        DB::statement('ALTER TABLE `notification_preferences` ADD UNIQUE `unique_notification_preference` (`notifiable_type`(80), `notifiable_id`, `notification_type`(50))');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};

