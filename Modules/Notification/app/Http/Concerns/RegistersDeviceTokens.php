<?php

namespace Modules\Notification\Http\Concerns;

use Illuminate\Http\Request;
use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Enums\DevicePlatform;
use Modules\Notification\Services\DeviceTokenService;
use Psr\Log\LoggerInterface;

/**
 * Lets an auth controller register the caller's FCM token as part of sign-in,
 * so a user is push-addressable from their first authenticated moment instead
 * of only after the client remembers to call POST /device-tokens.
 *
 * Two rules govern everything here:
 *
 *  1. **Push registration must never break authentication.** Every failure is
 *     swallowed and logged. A user locked out because Firebase had a bad minute
 *     is a far worse outcome than a missed notification.
 *  2. **Every field is optional.** Web clients and older app builds send no
 *     `fcm_token` at all and must keep logging in unchanged.
 */
trait RegistersDeviceTokens
{
    use DeviceTokenLoginRules;

    /**
     * Bind the request's `fcm_token` to the user who just authenticated.
     *
     * Idempotent, and safe on a shared handset: registering a token another
     * account holds moves it to this user and strips the previous owner's topic
     * subscriptions.
     *
     * @param  DeviceApp  $defaultApp  which client this endpoint serves; the
     *                                 request may override it.
     */
    protected function registerLoginDevice(
        Request $request,
        ?object $user,
        DeviceApp $defaultApp = DeviceApp::MOBILE
    ): void {
        $token = $request->input('fcm_token');

        if ($user === null || ! is_string($token) || trim($token) === '') {
            return;
        }

        if (! method_exists($user, 'deviceTokens')) {
            return;
        }

        try {
            app(DeviceTokenService::class)->register($user, trim($token), [
                'platform' => $request->input('platform', DevicePlatform::ANDROID->value),
                'app' => $request->input('app', $defaultApp->value),
                'locale' => $request->input('locale', app()->getLocale()),
                'device_id' => $request->input('device_id'),
                'device_name' => $request->input('device_name'),
                'app_version' => $request->input('app_version'),
            ]);
        } catch (\Throwable $e) {
            // Deliberately broad: nothing about push delivery justifies failing
            // a login that already succeeded.
            app(LoggerInterface::class)->warning('Device token registration on login failed', [
                'user_type' => $user::class,
                'user_id' => method_exists($user, 'getKey') ? $user->getKey() : null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Release the device on sign-out, so the next person to use the handset does
     * not inherit this user's notifications.
     *
     * A logout that omits `fcm_token` is a no-op — the server cannot guess which
     * of the user's devices is signing out.
     */
    protected function revokeLoginDevice(Request $request, ?object $user): void
    {
        $token = $request->input('fcm_token');

        if ($user === null || ! is_string($token) || trim($token) === '') {
            return;
        }

        if (! method_exists($user, 'deviceTokens')) {
            return;
        }

        try {
            app(DeviceTokenService::class)->unregister($user, trim($token));
        } catch (\Throwable $e) {
            app(LoggerInterface::class)->warning('Device token revocation on logout failed', [
                'user_type' => $user::class,
                'user_id' => method_exists($user, 'getKey') ? $user->getKey() : null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
