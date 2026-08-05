<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\FixedAssets\Enums\BrandOwnerNotificationSettingType;
use Modules\Notification\Services\NotificationAlertSettingService;

/**
 * «Notifications & alerts» — the SAME screen for every mobile role.
 *
 * The app used to call /brand-owner/settings/notifications for everyone, so a
 * branch manager or cashier saw «Unauthorized. Brand Owner access required.»
 * (2026-08-04). Identical payload shape, so the screen needs only its base URL
 * changed; the brand-owner route stays for compatibility.
 */
class NotificationAlertSettingController extends BaseController
{
    public function __construct(private readonly NotificationAlertSettingService $settings) {}

    /** GET /api/v1/settings/notifications */
    public function show(): JsonResponse
    {
        return $this->successResponse(
            ['notifications' => $this->settings->forActor(auth()->user())],
            'Notification settings retrieved successfully',
        );
    }

    /** PATCH /api/v1/settings/notifications */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notifications' => 'required|array|min:1',
            'notifications.*.type' => 'required|string|in:'.implode(',', BrandOwnerNotificationSettingType::values()),
            'notifications.*.enabled' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $rows = $this->settings->update(auth()->user(), $validator->validated()['notifications']);

        return $this->successResponse(
            ['notifications' => $rows],
            'Notification settings updated successfully',
        );
    }
}
