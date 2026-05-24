<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Support\Facades\DB;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Models\BrandOwnerSettingApproval;
use Modules\BrandOwner\Models\BrandOwnerSettingNotification;
use Modules\BrandOwner\Models\BrandOwnerSettingReport;
use Modules\BrandOwner\Models\BrandOwnerSettingRetention;
use Modules\BrandOwner\Models\BrandOwnerSettingSecurity;
use Modules\FixedAssets\Enums\BrandOwnerNotificationSettingType;
use Modules\FixedAssets\Enums\BrandOwnerReportSettingReport;

class BrandOwnerSettingsService
{
    public function approval(BrandOwner $owner): BrandOwnerSettingApproval
    {
        return BrandOwnerSettingApproval::firstOrCreate(
            ['brand_owner_id' => $owner->id],
            [
                'response_time_hours' => 24,
                'initial_audit_minimum_assets' => 50,
                'initial_audit_excellent_ratio' => 85,
                'personal_approval_for_all_new_branches' => false,
                'auto_approve_transfers_within' => 20000,
                'auto_approve_modifications_within' => 15000,
                'auto_approve_disposals_within' => 10000,
                'critical_assets_always_require_approval' => false,
            ],
        );
    }

    public function updateApproval(BrandOwner $owner, array $payload): BrandOwnerSettingApproval
    {
        $row = $this->approval($owner);
        $row->fill(array_intersect_key($payload, array_flip([
            'response_time_hours',
            'initial_audit_minimum_assets',
            'initial_audit_excellent_ratio',
            'personal_approval_for_all_new_branches',
            'auto_approve_transfers_within',
            'auto_approve_modifications_within',
            'auto_approve_disposals_within',
            'critical_assets_always_require_approval',
        ])))->save();

        return $row->fresh();
    }

    public function report(BrandOwner $owner): BrandOwnerSettingReport
    {
        return BrandOwnerSettingReport::firstOrCreate(
            ['brand_owner_id' => $owner->id],
            [
                'monthly_reports' => [],
                'quarterly_reports' => [],
                'annual_reports' => [],
            ],
        );
    }

    public function updateReport(BrandOwner $owner, array $payload): BrandOwnerSettingReport
    {
        $row = $this->report($owner);
        $allowed = BrandOwnerReportSettingReport::values();

        $clean = function (array $vals) use ($allowed) {
            return array_values(array_intersect(array_unique($vals), $allowed));
        };

        $row->fill([
            'monthly_reports' => $clean((array) ($payload['monthly_reports'] ?? $row->monthly_reports ?? [])),
            'quarterly_reports' => $clean((array) ($payload['quarterly_reports'] ?? $row->quarterly_reports ?? [])),
            'annual_reports' => $clean((array) ($payload['annual_reports'] ?? $row->annual_reports ?? [])),
        ])->save();

        return $row->fresh();
    }

    public function security(BrandOwner $owner): BrandOwnerSettingSecurity
    {
        return BrandOwnerSettingSecurity::firstOrCreate(
            ['brand_owner_id' => $owner->id],
            [
                'data_encryption' => true,
                'daily_backup_enabled' => true,
                'daily_backup_interval_hours' => 24,
                'monthly_security_audit' => false,
            ],
        );
    }

    public function updateSecurity(BrandOwner $owner, array $payload): BrandOwnerSettingSecurity
    {
        $row = $this->security($owner);
        $row->fill(array_intersect_key($payload, array_flip([
            'data_encryption',
            'daily_backup_enabled',
            'daily_backup_interval_hours',
            'monthly_security_audit',
        ])))->save();

        return $row->fresh();
    }

    public function retention(BrandOwner $owner): BrandOwnerSettingRetention
    {
        return BrandOwnerSettingRetention::firstOrCreate(
            ['brand_owner_id' => $owner->id],
            [
                'photo_retention_years' => 3,
                'handover_reports_retention_years' => 5,
            ],
        );
    }

    public function updateRetention(BrandOwner $owner, array $payload): BrandOwnerSettingRetention
    {
        $row = $this->retention($owner);
        $row->fill(array_intersect_key($payload, array_flip([
            'photo_retention_years',
            'handover_reports_retention_years',
        ])))->save();

        return $row->fresh();
    }

    public function notifications(BrandOwner $owner): array
    {
        $existing = BrandOwnerSettingNotification::query()
            ->where('brand_owner_id', $owner->id)
            ->get()
            ->keyBy('type');

        $result = [];
        foreach (BrandOwnerNotificationSettingType::values() as $type) {
            $row = $existing[$type] ?? null;
            if (! $row) {
                $row = BrandOwnerSettingNotification::create([
                    'brand_owner_id' => $owner->id,
                    'type' => $type,
                    'enabled' => false,
                ]);
            }
            $result[] = $row;
        }

        return $result;
    }

    public function updateNotifications(BrandOwner $owner, array $payload): array
    {
        $allowed = BrandOwnerNotificationSettingType::values();

        return DB::transaction(function () use ($owner, $payload, $allowed) {
            foreach ((array) ($payload['notifications'] ?? []) as $row) {
                $type = (string) ($row['type'] ?? '');
                if (! in_array($type, $allowed, true)) {
                    continue;
                }
                BrandOwnerSettingNotification::updateOrCreate(
                    ['brand_owner_id' => $owner->id, 'type' => $type],
                    ['enabled' => (bool) ($row['enabled'] ?? false)],
                );
            }

            return $this->notifications($owner);
        });
    }
}
