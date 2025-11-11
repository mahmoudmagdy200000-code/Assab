<?php

namespace Modules\Settings\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Settings\Models\UserSetting;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

/**
 * Settings Service
 */
class SettingsService
{
    /**
     * Get user settings
     */
    public function getUserSettings(string $userId, string $userType): UserSetting
    {
        return UserSetting::firstOrCreate(
            [
                'userable_id' => $userId,
                'userable_type' => $userType,
            ],
            [
                'language' => 'ar',
                'theme' => 'light',
                'notification_shift_variance' => true,
                'notification_daily_inventory' => true,
                'notification_approved_aggregators' => true,
                'notification_asset_transfers' => true,
                'notification_split_shift_handover' => true,
            ]
        );
    }

    /**
     * Update profile settings
     */
    public function updateProfile(string $userId, string $userType, array $data)
    {
        if ($userType === 'branch_manager') {
            $user = BranchManager::findOrFail($userId);
        } else {
            $user = Cashier::findOrFail($userId);
        }

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }

        if (isset($data['image'])) {
            // Delete old image if exists
            if ($user->image && Storage::disk('public')->exists($user->image)) {
                Storage::disk('public')->delete($user->image);
            }

            // Upload new image
            $filename = 'profile_' . $userId . '_' . time() . '.' . $data['image']->getClientOriginalExtension();
            $path = $data['image']->storeAs('profiles', $filename, 'public');
            $user->image = $path;
        }

        $user->save();

        return $user;
    }

    /**
     * Update system settings (language & theme)
     */
    public function updateSystemSettings(string $userId, string $userType, array $data): UserSetting
    {
        $settings = $this->getUserSettings($userId, $userType);

        if (isset($data['language'])) {
            $settings->language = $data['language'];
        }

        if (isset($data['theme'])) {
            $settings->theme = $data['theme'];
        }

        $settings->save();

        return $settings;
    }

    /**
     * Update notification preferences
     */
    public function updateNotificationSettings(string $userId, string $userType, array $data): UserSetting
    {
        $settings = $this->getUserSettings($userId, $userType);

        if (isset($data['shift_variance'])) {
            $settings->notification_shift_variance = $data['shift_variance'];
        }

        if (isset($data['daily_inventory'])) {
            $settings->notification_daily_inventory = $data['daily_inventory'];
        }

        if (isset($data['approved_aggregators'])) {
            $settings->notification_approved_aggregators = $data['approved_aggregators'];
        }

        if (isset($data['asset_transfers'])) {
            $settings->notification_asset_transfers = $data['asset_transfers'];
        }

        if (isset($data['split_shift_handover'])) {
            $settings->notification_split_shift_handover = $data['split_shift_handover'];
        }

        $settings->save();

        return $settings;
    }

    /**
     * Get account details with branch info
     */
    public function getAccountDetails(string $branchManagerId): array
    {
        $manager = BranchManager::with('branch')->findOrFail($branchManagerId);

        return [
            'account' => [
                'id' => $manager->id,
                'name' => $manager->name,
                'email' => $manager->email,
                'phone' => $manager->phone,
                'image' => $manager->image ? asset('storage/' . $manager->image) : null,
                'position' => 'Branch Manager',
                'created_at' => $manager->created_at->format('Y-m-d H:i:s'),
            ],
            'branch' => [
                'id' => $manager->branch->id,
                'name' => $manager->branch->name,
                'image' => $manager->branch->image ? asset('storage/' . $manager->branch->image) : null,
                'opening_hours' => $manager->branch->opening_hours ?? 'Mon-Fri / 9:00 AM - 8:00 PM',
                'location' => [
                    'latitude' => $manager->branch->latitude,
                    'longitude' => $manager->branch->longitude,
                    'address' => $manager->branch->address,
                ],
                'total_cashiers' => $manager->branch->cashiers()->count(),
                'total_aggregators' => $manager->branch->aggregators()->count(),
            ],
        ];
    }
}
