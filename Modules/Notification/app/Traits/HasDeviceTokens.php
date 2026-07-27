<?php

namespace Modules\Notification\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Notification\Models\DeviceToken;

/**
 * Makes a model addressable by FCM. Applied to every authenticatable model so a
 * single NotificationService call reaches whichever world the human logged in from.
 */
trait HasDeviceTokens
{
    public function deviceTokens(): MorphMany
    {
        return $this->morphMany(DeviceToken::class, 'notifiable');
    }

    /**
     * Raw FCM registration tokens for this owner.
     *
     * @return array<int, string>
     */
    public function routeNotificationForFcm(): array
    {
        return $this->deviceTokens()
            ->select(['id', 'token'])
            ->pluck('token')
            ->all();
    }

    /**
     * Preferred device locale, falling back to the app locale. Used to render
     * push copy in the language the device actually last reported.
     */
    public function preferredPushLocale(): string
    {
        return $this->deviceTokens()
            ->orderByDesc('last_used_at')
            ->value('locale') ?? config('app.locale', 'en');
    }
}
