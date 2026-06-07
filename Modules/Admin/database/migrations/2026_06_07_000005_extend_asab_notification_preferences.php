<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the per-user notification preferences for the settings page
 * (MISSING_Dashboard §7): in-app + whatsapp channels, a custom email address,
 * a per-event channel matrix, and quiet hours. Reuses email_enabled/push_enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_notification_preferences')) {
            return;
        }

        Schema::table('asab_notification_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('asab_notification_preferences', 'inapp_enabled')) {
                $table->boolean('inapp_enabled')->default(true);
            }
            if (! Schema::hasColumn('asab_notification_preferences', 'whatsapp_enabled')) {
                $table->boolean('whatsapp_enabled')->default(false);
            }
            if (! Schema::hasColumn('asab_notification_preferences', 'email_address')) {
                $table->string('email_address', 200)->nullable();
            }
            if (! Schema::hasColumn('asab_notification_preferences', 'events')) {
                $table->json('events')->nullable();
            }
            if (! Schema::hasColumn('asab_notification_preferences', 'quiet_hours')) {
                $table->json('quiet_hours')->nullable();
            }
        });
    }

    public function down(): void
    {
        // additive only — no-op
    }
};
