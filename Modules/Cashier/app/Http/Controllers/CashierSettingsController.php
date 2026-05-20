<?php

namespace Modules\Cashier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Cashier\Services\ProfileService;
use Modules\Settings\Services\SettingsService;

/**
 * Cashier Settings (3.2.1.3): Profile, Account & Branch, Notifications, System
 */
class CashierSettingsController extends BaseController
{
    public function __construct(
        private ProfileService $profileService,
        private SettingsService $settingsService
    ) {}

    /**
     * GET /cashier/settings – Profile, Account & Branch, Notifications, System
     */
    public function index(): JsonResponse
    {
        $cashier = auth()->user();
        $profile = $this->profileService->getProfile($cashier->id);
        $accountBranch = $this->settingsService->getAccountDetailsForCashier($cashier->id);
        $userSettings = $this->settingsService->getSettingsForCashier($cashier->id);

        return $this->successResponse([
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'email' => $profile->email,
                'phone' => $profile->phone,
                'image' => $profile->image_url ?? null,
                'position' => 'Cashier',
                'account_created_at' => $profile->created_at?->format('Y-m-d H:i:s'),
                'created_by' => $profile->creator ? ['id' => $profile->creator->id, 'name' => $profile->creator->name] : null,
            ],
            'account' => $accountBranch['account'],
            'branch' => $accountBranch['branch'],
            'notifications' => [
                'shift_variance_alerts' => $userSettings->notification_shift_variance ?? true,
                'daily_inventory_reminders' => $userSettings->notification_daily_inventory ?? true,
                'asset_transfers_only' => $userSettings->notification_asset_transfers ?? true,
                'allow_split_shift_handover' => $userSettings->notification_split_shift_handover ?? true,
            ],
            'system' => [
                'language' => $userSettings->language ?? 'ar',
                'theme' => $userSettings->theme ?? 'light',
            ],
        ], 'Settings retrieved successfully');
    }

    /**
     * GET /cashier/settings/account – Account details + branch details
     */
    public function account(): JsonResponse
    {
        $cashier = auth()->user();
        $data = $this->settingsService->getAccountDetailsForCashier($cashier->id);

        return $this->successResponse($data, 'Account and branch details retrieved successfully');
    }

    /**
     * PUT /cashier/settings/notifications – Notifications & Alerts (3.2.1.3)
     */
    public function updateNotifications(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_variance_alerts' => 'sometimes|boolean',
            'daily_inventory_reminders' => 'sometimes|boolean',
            'asset_transfers_only' => 'sometimes|boolean',
            'allow_split_shift_handover' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $cashier = auth()->user();
        $settings = $this->settingsService->getSettingsForCashier($cashier->id);

        $payload = [];
        if ($request->has('shift_variance_alerts')) {
            $payload['shift_variance'] = $request->boolean('shift_variance_alerts');
        }
        if ($request->has('daily_inventory_reminders')) {
            $payload['daily_inventory'] = $request->boolean('daily_inventory_reminders');
        }
        if ($request->has('asset_transfers_only')) {
            $payload['asset_transfers'] = $request->boolean('asset_transfers_only');
        }
        if ($request->has('allow_split_shift_handover')) {
            $payload['split_shift_handover'] = $request->boolean('allow_split_shift_handover');
        }

        if (! empty($payload)) {
            $this->settingsService->updateNotificationSettings($cashier->id, \Modules\Cashier\Models\Cashier::class, $payload);
            $settings = $this->settingsService->getSettingsForCashier($cashier->id);
        }

        return $this->successResponse([
            'shift_variance_alerts' => $settings->notification_shift_variance ?? true,
            'daily_inventory_reminders' => $settings->notification_daily_inventory ?? true,
            'asset_transfers_only' => $settings->notification_asset_transfers ?? true,
            'allow_split_shift_handover' => $settings->notification_split_shift_handover ?? true,
        ], 'Notification settings updated successfully');
    }

    /**
     * PUT /cashier/settings/system – Language & Theme (3.2.1.3)
     */
    public function updateSystem(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'language' => 'sometimes|in:ar,en',
            'theme' => 'sometimes|in:light,dark',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $cashier = auth()->user();
        $settings = $this->settingsService->updateSystemSettings(
            $cashier->id,
            \Modules\Cashier\Models\Cashier::class,
            $request->only(['language', 'theme'])
        );

        return $this->successResponse([
            'language' => $settings->language,
            'theme' => $settings->theme,
        ], 'System settings updated successfully');
    }
}
