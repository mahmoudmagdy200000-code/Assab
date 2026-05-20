<?php

namespace Modules\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Settings\Services\SettingsService;

/**
 * Settings Controller
 * Handles all settings-related operations
 */
class SettingsController extends Controller
{
    public function __construct(
        private SettingsService $settingsService
    ) {}

    /**
     * Get all settings (profile, system, notifications, account, branch)
     * GET /api/branch-manager/settings
     */
    public function index(): JsonResponse
    {
        try {
            $userId = auth()->id();
            $userType = 'branch_manager'; // or get from auth

            // Get user settings
            $userSettings = $this->settingsService->getUserSettings($userId, $userType);

            // Get account & branch details
            $accountDetails = $this->settingsService->getAccountDetails($userId);

            return response()->json([
                'success' => true,
                'message' => 'Settings retrieved successfully',
                'data' => [
                    'profile' => [
                        'name' => auth()->user()->name,
                        'image' => auth()->user()->image ? asset('storage/'.auth()->user()->image) : null,
                        'position' => 'Branch Manager',
                        'created_at' => auth()->user()->created_at->format('Y-m-d H:i:s'),
                    ],
                    'system' => [
                        'language' => $userSettings->language,
                        'theme' => $userSettings->theme,
                    ],
                    'notifications' => [
                        'shift_variance' => $userSettings->notification_shift_variance,
                        'daily_inventory' => $userSettings->notification_daily_inventory,
                        'approved_aggregators' => $userSettings->notification_approved_aggregators,
                        'asset_transfers' => $userSettings->notification_asset_transfers,
                        'split_shift_handover' => $userSettings->notification_split_shift_handover,
                    ],
                    'account' => $accountDetails['account'],
                    'branch' => $accountDetails['branch'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve settings',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update profile settings
     * PUT /api/branch-manager/settings/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'image' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $userId = auth()->id();
            $userType = 'branch_manager';

            $data = $request->only(['name']);
            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image');
            }

            $user = $this->settingsService->updateProfile($userId, $userType, $data);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'name' => $user->name,
                    'image' => $user->image ? asset('storage/'.$user->image) : null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update system settings (language & theme)
     * PUT /api/branch-manager/settings/system
     */
    public function updateSystemSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'language' => 'sometimes|in:ar,en',
            'theme' => 'sometimes|in:light,dark',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $userId = auth()->id();
            $userType = 'branch_manager';

            $settings = $this->settingsService->updateSystemSettings(
                $userId,
                $userType,
                $request->only(['language', 'theme'])
            );

            return response()->json([
                'success' => true,
                'message' => 'System settings updated successfully',
                'data' => [
                    'language' => $settings->language,
                    'theme' => $settings->theme,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update system settings',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update notification preferences
     * PUT /api/branch-manager/settings/notifications
     */
    public function updateNotificationSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_variance' => 'sometimes|boolean',
            'daily_inventory' => 'sometimes|boolean',
            'approved_aggregators' => 'sometimes|boolean',
            'asset_transfers' => 'sometimes|boolean',
            'split_shift_handover' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $userId = auth()->id();
            $userType = 'branch_manager';

            $settings = $this->settingsService->updateNotificationSettings(
                $userId,
                $userType,
                $request->all()
            );

            return response()->json([
                'success' => true,
                'message' => 'Notification settings updated successfully',
                'data' => [
                    'shift_variance' => $settings->notification_shift_variance,
                    'daily_inventory' => $settings->notification_daily_inventory,
                    'approved_aggregators' => $settings->notification_approved_aggregators,
                    'asset_transfers' => $settings->notification_asset_transfers,
                    'split_shift_handover' => $settings->notification_split_shift_handover,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update notification settings',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get account & branch details
     * GET /api/branch-manager/settings/account
     */
    public function getAccountDetails(): JsonResponse
    {
        try {
            $userId = auth()->id();
            $accountDetails = $this->settingsService->getAccountDetails($userId);

            return response()->json([
                'success' => true,
                'message' => 'Account details retrieved successfully',
                'data' => $accountDetails,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve account details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
