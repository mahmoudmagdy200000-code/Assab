<?php

namespace Modules\BranchManagers\Services;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Settings\Models\UserSetting;
use Modules\Aggregator\Models\BranchAggregator;

class SettingsService
{
    /**
     * Get all settings
     */
    public function getAllSettings(BranchManager $manager): array
    {
        return [
            'profile' => [
                'name' => $manager->name,
                'email' => $manager->email,
                'phone' => $manager->phone,
                'image' => $manager->image_url,
                'position' => 'Branch Manager',
                'created_at' => $manager->created_at->format('Y-m-d H:i:s'),
            ],
            'branch' => [
                'id' => $manager->branch->id,
                'name' => $manager->branch->name,
                'image' => $manager->branch->image ? asset('storage/' . $manager->branch->image) : null,
                'opening_hours' => $manager->branch->opening_hours,
                'location' => $manager->branch->location,
                'total_cashiers' => $manager->getTotalCashiers(),
                'total_aggregators' => BranchAggregator::where('branch_id', $manager->branch_id)->count(),
            ],
            'notifications' => $this->getNotificationSettings($manager),
            'system' => $this->getSystemSettings($manager),
        ];
    }

    /**
     * Get notification settings
     */
    public function getNotificationSettings(BranchManager $manager): array
    {
        $settings = UserSetting::where('user_id', $manager->id)
            ->where('user_type', get_class($manager))
            ->where('category', 'notifications')
            ->pluck('value', 'key')
            ->toArray();

        return [
            'shift_variance_alerts' => $settings['shift_variance_alerts'] ?? true,
            'daily_inventory_reminders' => $settings['daily_inventory_reminders'] ?? true,
            'approved_aggregators_only' => $settings['approved_aggregators_only'] ?? false,
            'asset_transfers_only' => $settings['asset_transfers_only'] ?? false,
            'allow_split_shift_handover' => $settings['allow_split_shift_handover'] ?? true,
        ];
    }

    /**
     * Update notification settings
     */
    public function updateNotificationSettings(BranchManager $manager, array $data): array
    {
        foreach ($data as $key => $value) {
            UserSetting::updateOrCreate(
                [
                    'user_id' => $manager->id,
                    'user_type' => get_class($manager),
                    'category' => 'notifications',
                    'key' => $key,
                ],
                [
                    'value' => $value,
                ]
            );
        }

        return $this->getNotificationSettings($manager);
    }

    /**
     * Get system settings
     */
    public function getSystemSettings(BranchManager $manager): array
    {
        $settings = UserSetting::where('user_id', $manager->id)
            ->where('user_type', get_class($manager))
            ->where('category', 'system')
            ->pluck('value', 'key')
            ->toArray();

        return [
            'language' => $settings['language'] ?? 'en',
            'theme' => $settings['theme'] ?? 'light',
        ];
    }

    /**
     * Update system settings
     */
    public function updateSystemSettings(BranchManager $manager, array $data): array
    {
        foreach ($data as $key => $value) {
            UserSetting::updateOrCreate(
                [
                    'user_id' => $manager->id,
                    'user_type' => get_class($manager),
                    'category' => 'system',
                    'key' => $key,
                ],
                [
                    'value' => $value,
                ]
            );
        }

        return $this->getSystemSettings($manager);
    }

    /**
     * Get branch settings
     */
    public function getBranchSettings(BranchManager $manager): array
    {
        $aggregators = BranchAggregator::where('branch_id', $manager->branch_id)
            ->with('aggregator')
            ->get();

        return [
            'branch' => [
                'id' => $manager->branch->id,
                'name' => $manager->branch->name,
                'total_cashiers' => $manager->getTotalCashiers(),
                'total_aggregators' => $aggregators->count(),
            ],
            'aggregators' => $aggregators->map(function ($ba) {
                return [
                    'id' => $ba->aggregator->id,
                    'name' => $ba->aggregator->name,
                    'logo' => $ba->aggregator->logo ? asset('storage/' . $ba->aggregator->logo) : null,
                    'is_enabled' => $ba->is_enabled,
                ];
            }),
        ];
    }

    /**
     * Update branch aggregators
     */
    public function updateBranchAggregators(BranchManager $manager, array $aggregatorIds): array
    {
        // Get current aggregators
        $currentAggregators = BranchAggregator::where('branch_id', $manager->branch_id)
            ->pluck('aggregator_id')
            ->toArray();

        // Add new aggregators
        $newAggregators = array_diff($aggregatorIds, $currentAggregators);
        foreach ($newAggregators as $aggregatorId) {
            BranchAggregator::create([
                'branch_id' => $manager->branch_id,
                'aggregator_id' => $aggregatorId,
                'is_enabled' => true,
            ]);
        }

        // Remove old aggregators
        $removeAggregators = array_diff($currentAggregators, $aggregatorIds);
        BranchAggregator::where('branch_id', $manager->branch_id)
            ->whereIn('aggregator_id', $removeAggregators)
            ->delete();

        return $this->getBranchSettings($manager);
    }
}
