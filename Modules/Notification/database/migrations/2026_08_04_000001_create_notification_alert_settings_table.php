<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mobile «Notifications & alerts» screen is shown to EVERY role, but the
 * only endpoint behind it was /brand-owner/settings/notifications, whose store
 * (`brand_owner_setting_notifications`) has a hard FK to brand_owners. A branch
 * manager or cashier opening the screen got «Unauthorized. Brand Owner access
 * required.» (2026-08-04). This table is the same setting keyed by a POLYMORPHIC
 * notifiable, so any mobile actor owns their own toggles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_alert_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('notifiable'); // BranchManager, Cashier, BrandOwner, Supplier…
            $table->string('type', 64);
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'type'], 'notif_alert_setting_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_alert_settings');
    }
};
