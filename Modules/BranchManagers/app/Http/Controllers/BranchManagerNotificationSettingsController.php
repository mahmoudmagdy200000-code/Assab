<?php

namespace Modules\BranchManagers\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\BranchManagers\Enums\NotificationSettingType;
use Modules\BranchManagers\Http\Requests\Settings\ToggleEnabledRequest;
use Modules\BranchManagers\Services\BranchManagerSettingsService;
use Modules\BranchManagers\Transformers\Settings\NotificationSettingResource;

/**
 * Notification preference toggles on the branch manager settings screen.
 */
class BranchManagerNotificationSettingsController extends BaseController
{
    public function __construct(
        private readonly BranchManagerSettingsService $settingsService,
    ) {}

    /**
     * PATCH /branch-manager/settings/notifications/shift-variance-alerts
     */
    public function shiftVarianceAlerts(ToggleEnabledRequest $request): JsonResponse
    {
        return $this->toggle(NotificationSettingType::ShiftVarianceAlerts, $request);
    }

    /**
     * PATCH /branch-manager/settings/notifications/daily-inventory-reminders
     */
    public function dailyInventoryReminders(ToggleEnabledRequest $request): JsonResponse
    {
        return $this->toggle(NotificationSettingType::DailyInventoryReminders, $request);
    }

    /**
     * PATCH /branch-manager/settings/notifications/approved-aggregators-only
     */
    public function approvedAggregatorsOnly(ToggleEnabledRequest $request): JsonResponse
    {
        return $this->toggle(NotificationSettingType::ApprovedAggregatorsOnly, $request);
    }

    /**
     * PATCH /branch-manager/settings/notifications/asset-transfer-requests
     */
    public function assetTransferRequests(ToggleEnabledRequest $request): JsonResponse
    {
        return $this->toggle(NotificationSettingType::AssetTransferRequests, $request);
    }

    /**
     * PATCH /branch-manager/settings/notifications/allow-split-shift-handovers
     */
    public function allowSplitShiftHandovers(ToggleEnabledRequest $request): JsonResponse
    {
        return $this->toggle(NotificationSettingType::AllowSplitShiftHandovers, $request);
    }

    private function toggle(NotificationSettingType $type, ToggleEnabledRequest $request): JsonResponse
    {
        $setting = $this->settingsService->toggleNotification(
            auth()->user(),
            $type,
            $request->enabled(),
        );

        return $this->successResponse(
            new NotificationSettingResource([
                'type' => $type,
                'enabled' => (bool) $setting->{$type->column()},
            ]),
            $type->title().' updated successfully',
        );
    }
}
