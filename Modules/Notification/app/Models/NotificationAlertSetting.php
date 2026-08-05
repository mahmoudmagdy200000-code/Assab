<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One «Notifications & alerts» toggle for one mobile actor. Polymorphic on
 * purpose: the screen is role-agnostic, unlike the brand-owner-only table it
 * replaces for every other role.
 */
class NotificationAlertSetting extends Model
{
    use HasUuids;

    protected $table = 'notification_alert_settings';

    protected $fillable = ['notifiable_type', 'notifiable_id', 'type', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
