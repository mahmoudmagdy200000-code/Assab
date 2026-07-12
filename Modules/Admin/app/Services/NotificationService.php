<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AsabUserRole;

/**
 * Persists in-app notifications and pushes them in real time
 * (BACKEND_API_SPEC.md §7 notifications + §8 `notification.new`).
 */
class NotificationService
{
    public function __construct(private readonly RealtimeBroadcaster $rt) {}

    /**
     * @param  array{type?:string, id?:string}  $ref
     */
    public function push(string $userId, string $type, string $title, ?string $body = null, ?string $link = null, array $ref = []): AsabNotification
    {
        $n = AsabNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'ref_type' => $ref['type'] ?? null,
            'ref_id' => $ref['id'] ?? null,
        ]);

        $this->rt->notificationNew($n);

        return $n;
    }

    /**
     * Fan a notification out to every user holding a role in a company.
     *
     * @return int number of users notified
     */
    public function pushToRole(string $companyId, string $roleKey, string $type, string $title, ?string $body = null, ?string $link = null, array $ref = []): int
    {
        $userIds = AsabUserRole::query()
            ->where('role_key', $roleKey)
            ->whereHas('user', fn ($q) => $q->where('company_id', $companyId))
            ->pluck('user_id')
            ->unique();

        foreach ($userIds as $uid) {
            $this->push($uid, $type, $title, $body, $link, $ref);
        }

        return $userIds->count();
    }

    /**
     * Fan a notification out to users holding `$roleKey` whose assignment covers
     * a specific branch (scope=all, or the branch / its restaurant / its brand in
     * the assignment). Branch-targeted counterpart of pushToRole.
     *
     * @return int number of users notified
     */
    public function pushToBranch(string $companyId, string $branchId, string $roleKey, string $type, string $title, ?string $body = null, ?string $link = null, array $ref = []): int
    {
        $branch = \Modules\Branch\Models\Branch::where('id', $branchId)->first(['id', 'asab_brand_id', 'asab_restaurant_id']);
        $brandId = $branch?->asab_brand_id;
        $restaurantId = $branch?->asab_restaurant_id;

        $roles = AsabUserRole::query()
            ->where('role_key', $roleKey)
            ->whereHas('user', fn ($q) => $q->where('company_id', $companyId))
            ->get(['user_id', 'scope', 'brand_ids', 'restaurant_ids', 'branch_ids']);

        $userIds = $roles->filter(function ($role) use ($branchId, $brandId, $restaurantId) {
            return $role->scope === 'all'
                || in_array($branchId, $role->branch_ids ?? [], true)
                || ($restaurantId !== null && in_array($restaurantId, $role->restaurant_ids ?? [], true))
                || ($brandId !== null && in_array($brandId, $role->brand_ids ?? [], true));
        })->pluck('user_id')->unique();

        foreach ($userIds as $uid) {
            $this->push($uid, $type, $title, $body, $link, $ref);
        }

        return $userIds->count();
    }
}
