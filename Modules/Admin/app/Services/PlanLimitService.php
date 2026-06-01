<?php

namespace Modules\Admin\Services;

use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\Plan;
use Modules\Admin\Models\SubscriptionQuotaUsage;

/**
 * Plan-limit enforcement (COMPANY_DASHBOARD_API_SPEC.md §1.4). Computes live
 * usage and blocks writes that would exceed the company's plan caps with
 * 409 QUOTA_EXCEEDED. null cap = unlimited.
 */
class PlanLimitService
{
    public function __construct(private readonly RealtimeBroadcaster $rt) {}

    public function planFor(string $companyId): ?Plan
    {
        return optional(
            CompanySubscription::withoutGlobalScopes()->where('company_id', $companyId)->first()
        )?->plan;
    }

    /** @return array<string, int> live usage counts */
    public function usage(string $companyId): array
    {
        $branches = 0;
        try {
            $branches = \Modules\Branch\Models\Branch::where('asab_company_id', $companyId)->count();
        } catch (\Throwable) {
        }

        return [
            'branches' => $branches,
            'users' => AsabUser::withoutGlobalScopes()->where('company_id', $companyId)->count(),
            'brands' => AsabBrand::withoutGlobalScopes()->where('company_id', $companyId)->count(),
            'restaurants' => AsabRestaurant::withoutGlobalScopes()->where('company_id', $companyId)->count(),
        ];
    }

    /**
     * Quota descriptor per resource: { used, max } (max null = unlimited).
     *
     * @return array<string, array{used:int, max:?int}>
     */
    public function quotas(string $companyId): array
    {
        $plan = $this->planFor($companyId);
        $use = $this->usage($companyId);

        return [
            'brands' => ['used' => $use['brands'], 'max' => $plan?->max_brands],
            'restaurants' => ['used' => $use['restaurants'], 'max' => $plan?->max_restaurants],
            'branches' => ['used' => $use['branches'], 'max' => $plan?->max_branches],
            'users' => ['used' => $use['users'], 'max' => $plan?->max_users],
        ];
    }

    /** Throw QUOTA_EXCEEDED if adding one more of $resource would breach the cap. */
    public function assertCanAdd(string $companyId, string $resource): void
    {
        $q = $this->quotas($companyId)[$resource] ?? null;
        if (! $q || $q['max'] === null) {
            return;
        }
        if ($q['used'] >= $q['max']) {
            $this->rt->quotaExceeded($companyId, $resource, $q['used'], $q['max']);

            throw new AsabException(
                'QUOTA_EXCEEDED',
                "Plan limit reached for {$resource}",
                'تم بلوغ الحد الأقصى للخطة الحالية',
                409,
                ['resource' => $resource, 'used' => $q['used'], 'max' => $q['max']],
            );
        }
        // Approaching the cap (this add would put usage at >=80%): warn the admins.
        if (($q['used'] + 1) / $q['max'] >= 0.8) {
            $this->rt->quotaWarning($companyId, $resource, $q['used'] + 1, $q['max']);
        }
    }

    /**
     * If downgrading to $targetPlan would leave usage above its caps, list the
     * offending resources (422 QUOTA_WOULD_EXCEED).
     *
     * @return array<int, array{resource:string, used:int, max:int}>
     */
    public function downgradeViolations(string $companyId, Plan $targetPlan): array
    {
        $use = $this->usage($companyId);
        $caps = ['branches' => $targetPlan->max_branches, 'users' => $targetPlan->max_users,
            'brands' => $targetPlan->max_brands, 'restaurants' => $targetPlan->max_restaurants];
        $violations = [];
        foreach ($caps as $res => $max) {
            if ($max !== null && ($use[$res] ?? 0) > $max) {
                $violations[] = ['resource' => $res, 'used' => $use[$res], 'max' => $max];
            }
        }

        return $violations;
    }

    /** Refresh the cached usage snapshot row. */
    public function refreshSnapshot(string $companyId): void
    {
        $use = $this->usage($companyId);
        SubscriptionQuotaUsage::updateOrCreate(['company_id' => $companyId], [
            'used_branches' => $use['branches'], 'used_users' => $use['users'],
            'used_brands' => $use['brands'], 'used_restaurants' => $use['restaurants'],
            'computed_at' => now(),
        ]);
    }
}
