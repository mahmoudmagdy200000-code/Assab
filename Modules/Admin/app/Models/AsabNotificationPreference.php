<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-user notification preferences (MISSING_Dashboard §7). Primary key is the
 * user id (one row per user).
 */
class AsabNotificationPreference extends Model
{
    protected $table = 'asab_notification_preferences';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'inapp_enabled', 'email_enabled', 'push_enabled', 'whatsapp_enabled',
        'email_address', 'events', 'quiet_hours',
    ];

    protected $casts = [
        'inapp_enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean',
        'events' => 'array',
        'quiet_hours' => 'array',
    ];
}
