<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;

class NotificationPreference extends Model
{
    use HasUuids;

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'notification_type',
        'channels',
        'priority_level',
        'enabled',
    ];

    protected $casts = [
        'channels' => 'array',
        'enabled' => 'boolean',
        'notification_type' => NotificationType::class,
        'priority_level' => NotificationPriority::class,
    ];

    protected $attributes = [
        'channels' => '["app"]',
        'enabled' => true,
        'priority_level' => 'low',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isChannelEnabled(NotificationChannel $channel): bool
    {
        return in_array($channel->value, $this->channels ?? []);
    }

    public function shouldReceive(NotificationPriority $priority): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $priority->numericValue() >= $this->priority_level->numericValue();
    }
}

