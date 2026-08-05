<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Facades\DB;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Models\BrandOwnerSettingNotification;
use Modules\FixedAssets\Enums\BrandOwnerNotificationSettingType;
use Modules\Notification\Models\NotificationAlertSetting;

/**
 * The role-agnostic «Notifications & alerts» settings the mobile screen reads.
 *
 * The type list is deliberately the one the brand-owner screen already ships
 * (asset audit / transfer / status change / handover / major discrepancies):
 * every one of those events involves the BRANCH side too, so one screen and one
 * contract serve every role instead of the app calling a brand-owner-only
 * endpoint and getting a 403 (2026-08-04).
 *
 * A brand owner's existing rows are the authority for them: their toggles were
 * saved before this table existed, so the first read SEEDS from
 * `brand_owner_setting_notifications` rather than silently resetting them.
 */
class NotificationAlertSettingService
{
    /** @return array<int, array{type: string, enabled: bool}> */
    public function forActor(object $actor): array
    {
        $existing = $this->rowsFor($actor)->keyBy('type');
        $legacy = $this->legacyBrandOwnerRows($actor);

        $out = [];
        foreach (BrandOwnerNotificationSettingType::values() as $type) {
            $row = $existing->get($type);

            if ($row === null) {
                $row = $this->write($actor, $type, (bool) ($legacy[$type] ?? false));
            }

            $out[] = ['type' => (string) $row->type, 'enabled' => (bool) $row->enabled];
        }

        return $out;
    }

    /**
     * @param  array<int, array{type: string, enabled: bool}>  $notifications
     * @return array<int, array{type: string, enabled: bool}>
     */
    public function update(object $actor, array $notifications): array
    {
        $allowed = BrandOwnerNotificationSettingType::values();

        return DB::transaction(function () use ($actor, $notifications, $allowed) {
            foreach ($notifications as $row) {
                $type = (string) ($row['type'] ?? '');
                if (! in_array($type, $allowed, true)) {
                    continue;
                }

                $this->write($actor, $type, (bool) ($row['enabled'] ?? false));
            }

            return $this->forActor($actor);
        });
    }

    private function write(object $actor, string $type, bool $enabled): NotificationAlertSetting
    {
        $setting = NotificationAlertSetting::updateOrCreate(
            [
                'notifiable_type' => $this->morphClass($actor),
                'notifiable_id' => (string) $actor->getKey(),
                'type' => $type,
            ],
            ['enabled' => $enabled],
        );

        // A brand owner keeps their legacy row in step, so the older
        // brand-owner-only endpoint and this one never disagree.
        if ($actor instanceof BrandOwner) {
            BrandOwnerSettingNotification::updateOrCreate(
                ['brand_owner_id' => $actor->id, 'type' => $type],
                ['enabled' => $enabled],
            );
        }

        return $setting;
    }

    /** @return \Illuminate\Support\Collection<int, NotificationAlertSetting> */
    private function rowsFor(object $actor)
    {
        return NotificationAlertSetting::query()
            ->where('notifiable_type', $this->morphClass($actor))
            ->where('notifiable_id', (string) $actor->getKey())
            ->get();
    }

    /** @return array<string, bool> type => enabled, empty for non-owners. */
    private function legacyBrandOwnerRows(object $actor): array
    {
        if (! $actor instanceof BrandOwner) {
            return [];
        }

        return BrandOwnerSettingNotification::where('brand_owner_id', $actor->id)
            ->pluck('enabled', 'type')
            ->map(fn ($enabled) => (bool) $enabled)
            ->all();
    }

    private function morphClass(object $actor): string
    {
        return method_exists($actor, 'getMorphClass') ? $actor->getMorphClass() : $actor::class;
    }
}
