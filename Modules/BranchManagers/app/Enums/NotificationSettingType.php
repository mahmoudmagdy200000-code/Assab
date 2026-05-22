<?php

namespace Modules\BranchManagers\Enums;

/**
 * Notification toggles shown on the branch manager settings screen.
 *
 * The enum value is the type identifier exposed to the API client.
 * It also bridges the persisted UserSetting column and the key accepted
 * by the shared SettingsService.
 */
enum NotificationSettingType: string
{
    case ShiftVarianceAlerts = 'shiftVarianceAlerts';
    case DailyInventoryReminders = 'dailyInventoryReminders';
    case ApprovedAggregatorsOnly = 'approvedAggregatorsOnly';
    case AssetTransferRequests = 'assetTransferRequests';
    case AllowSplitShiftHandovers = 'allowSplitShiftHandovers';

    /**
     * Column on the user_settings table backing this toggle.
     */
    public function column(): string
    {
        return match ($this) {
            self::ShiftVarianceAlerts => 'notification_shift_variance',
            self::DailyInventoryReminders => 'notification_daily_inventory',
            self::ApprovedAggregatorsOnly => 'notification_approved_aggregators',
            self::AssetTransferRequests => 'notification_asset_transfers',
            self::AllowSplitShiftHandovers => 'notification_split_shift_handover',
        };
    }

    /**
     * Key understood by SettingsService::updateNotificationSettings().
     */
    public function settingsKey(): string
    {
        return match ($this) {
            self::ShiftVarianceAlerts => 'shift_variance',
            self::DailyInventoryReminders => 'daily_inventory',
            self::ApprovedAggregatorsOnly => 'approved_aggregators',
            self::AssetTransferRequests => 'asset_transfers',
            self::AllowSplitShiftHandovers => 'split_shift_handover',
        };
    }

    /**
     * Human readable title rendered next to the toggle.
     */
    public function title(): string
    {
        return match ($this) {
            self::ShiftVarianceAlerts => 'Shift Variance Alerts',
            self::DailyInventoryReminders => 'Daily Inventory Reminders',
            self::ApprovedAggregatorsOnly => 'Approved Aggregators Only',
            self::AssetTransferRequests => 'Asset Transfer Requests',
            self::AllowSplitShiftHandovers => 'Allow Split Shift Handovers',
        };
    }

    /**
     * Supporting copy rendered under the title.
     */
    public function description(): string
    {
        return match ($this) {
            self::ShiftVarianceAlerts => 'Get notified whenever a handover report shows sales or payment discrepancies.',
            self::DailyInventoryReminders => 'Send push/email reminders to me & staff to start or complete daily inventory tasks.',
            self::ApprovedAggregatorsOnly => "Only allow payment platforms you've approved, unauthorized ones won't show up.",
            self::AssetTransferRequests => 'Get notified instantly when assets are being transferred in or out of your branch.',
            self::AllowSplitShiftHandovers => 'Enable cashiers to hand over in segments (e.g., lunch shift vs. dinner shift).',
        };
    }
}
