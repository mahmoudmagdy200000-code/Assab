<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Http\Requests\RegisterDeviceTokenRequest;
use Modules\Notification\Http\Requests\UnregisterDeviceTokenRequest;
use Modules\Notification\Http\Resources\DeviceTokenResource;
use Modules\Notification\Jobs\SendFcmMessageJob;
use Modules\Notification\Services\DeviceTokenService;

/**
 * Device registration for push notifications.
 *
 * Every route is scoped to the authenticated caller: there is no owner
 * parameter anywhere, so one user can neither register a token for another nor
 * enumerate someone else's devices.
 */
class DeviceTokenController extends BaseController
{
    public function __construct(
        private readonly DeviceTokenService $deviceTokenService,
    ) {}

    /**
     * List the caller's registered devices.
     */
    public function index(Request $request): JsonResponse
    {
        $app = DeviceApp::tryFrom((string) $request->query('app', ''));

        $devices = $this->deviceTokenService->forOwner($request->user(), $app);

        return $this->successResponse(
            DeviceTokenResource::collection($devices),
            'Registered devices retrieved successfully'
        );
    }

    /**
     * Register (or re-bind) an FCM registration token for the caller.
     *
     * Idempotent: the mobile client should call this on every launch and on
     * every FCM token refresh.
     */
    public function store(RegisterDeviceTokenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $device = $this->deviceTokenService->register(
            $request->user(),
            $data['token'],
            [
                'platform' => $data['platform'],
                'app' => $data['app'] ?? DeviceApp::MOBILE->value,
                'locale' => $data['locale'] ?? app()->getLocale(),
                'device_id' => $data['device_id'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
            ]
        );

        return $this->createdResponse(
            new DeviceTokenResource($device),
            'Device registered for push notifications'
        );
    }

    /**
     * Revoke one device. Call on sign-out so the next user of the handset does
     * not inherit the previous user's notifications.
     */
    public function destroy(UnregisterDeviceTokenRequest $request): JsonResponse
    {
        $this->deviceTokenService->unregister($request->user(), $request->validated()['token']);

        // Deliberately not reporting whether the token existed: the endpoint
        // must not double as an oracle for which tokens are registered.
        return $this->deletedResponse('Device unregistered from push notifications');
    }

    /**
     * Revoke every device for the caller (sign out everywhere).
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $count = $this->deviceTokenService->revokeAll($request->user());

        return $this->successResponse(
            ['revoked' => $count],
            'All devices unregistered from push notifications'
        );
    }

    /**
     * Fire a test push at the caller's own devices.
     *
     * Non-production only — it exists to verify the client's FCM wiring, and in
     * production it would be a free way to generate push traffic.
     */
    public function test(Request $request): JsonResponse
    {
        if (app()->environment('production')) {
            return $this->forbiddenResponse('Test notifications are disabled in production');
        }

        $user = $request->user();
        $devices = $this->deviceTokenService->forOwner($user);

        if ($devices->isEmpty()) {
            return $this->errorResponse('No registered devices for this account', 422);
        }

        SendFcmMessageJob::dispatch(
            $user->getMorphClass(),
            (string) $user->getKey(),
            NotificationType::AUDIT_TRAIL_NOTIFICATION,
            ['test' => true, 'requested_at' => now()->toIso8601String()],
            NotificationPriority::LOW,
            null,
        );

        return $this->successResponse(
            ['devices' => $devices->count()],
            'Test push queued'
        );
    }
}
