<?php

namespace Modules\Notification\Http\Concerns;

use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Enums\DevicePlatform;

/**
 * The optional device-registration fields a login (or first-login) request may
 * carry. Kept separate from RegistersDeviceTokens so a FormRequest can pull in
 * the rules without also inheriting controller-side behaviour.
 */
trait DeviceTokenLoginRules
{
    /**
     * Merge into a login request's rules.
     *
     * Everything is optional: web clients and older app builds send none of it
     * and must keep logging in unchanged.
     *
     * `platform` is optional on purpose — a client that sends only `fcm_token`
     * must not get a 422 on login. It is reporting metadata; FCM routes by the
     * token itself and every push carries android, apns and webpush blocks.
     *
     * @return array<string, mixed>
     */
    protected static function deviceTokenLoginRules(): array
    {
        return [
            'fcm_token' => ['sometimes', 'nullable', 'string', 'min:32', 'max:4096'],
            'platform' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', DevicePlatform::values())],
            'app' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', DeviceApp::values())],
            // Omitted, this falls back to the request locale (Accept-Language).
            'locale' => ['sometimes', 'nullable', 'string', 'in:en,ar'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
