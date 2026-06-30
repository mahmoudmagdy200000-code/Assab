<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabSubscription;

/**
 * Admin-layer subscription mutations (FE completion request §1.1). Shared by the
 * subscription-id endpoints and the per-restaurant renewal so the renewal date
 * math and presenter live in exactly one place (DRY).
 */
class AsabSubscriptionService
{
    /**
     * Renewal date math, shared by subscription renew and brand-level renew/activate
     * (Admin dashboard contract batch 1, A1/A2) so the "extend from later of now /
     * current expiry" rule lives in exactly one place (DRY).
     *
     * @return array{expires: Carbon, daysLeft: int}
     */
    public function computeRenewal(?Carbon $currentExpiry, int $months = 12): array
    {
        $base = $currentExpiry && $currentExpiry->isFuture() ? $currentExpiry : now();
        $expires = $base->copy()->addMonths(max(1, $months));

        return ['expires' => $expires, 'daysLeft' => (int) now()->diffInDays($expires)];
    }

    /** Extend a subscription by N months from the later of now / current expiry. */
    public function renew(AsabSubscription $sub, int $months = 12): AsabSubscription
    {
        return DB::transaction(function () use ($sub, $months) {
            $renewal = $this->computeRenewal($sub->expires_at, $months);
            $sub->update([
                'status' => 'active',
                'expires_at' => $renewal['expires'],
                'days_left' => $renewal['daysLeft'],
            ]);

            return $sub->fresh();
        });
    }

    /** Resolve the active subscription owned by a restaurant (newest expiry wins). */
    public function forRestaurant(string $restaurantId): ?AsabSubscription
    {
        return AsabSubscription::where('restaurant_id', $restaurantId)
            ->orderByDesc('expires_at')->first();
    }

    /**
     * Build a brandId => [{id,name}] map for a set of subscriptions in ONE query,
     * so present() can include each subscription's brand restaurants without N+1.
     *
     * @param  iterable<AsabSubscription>  $subs
     * @return array<string, array<int, array{id:string, name:?string}>>
     */
    public function restaurantsByBrand(iterable $subs): array
    {
        $brandIds = collect($subs)->pluck('brand_id')->filter()->unique();
        if ($brandIds->isEmpty()) {
            return [];
        }

        return AsabRestaurant::whereIn('brand_id', $brandIds)->get(['id', 'name', 'brand_id'])
            ->groupBy('brand_id')
            ->map(fn ($group) => $group->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()->all())
            ->all();
    }

    /**
     * @param  array<string, array<int, array{id:string, name:?string}>>  $restaurantsByBrand
     * @return array<string, mixed>
     */
    public function present(AsabSubscription $s, array $restaurantsByBrand = []): array
    {
        return [
            'id' => $s->id,
            'companyId' => $s->company_id,
            'brandId' => $s->brand_id,
            'restaurantId' => $s->restaurant_id,
            'plan' => $s->plan,
            'status' => $s->status,
            'expiresAt' => optional($s->expires_at)->toIso8601String(),
            'daysLeft' => $s->days_left,
            'monthlyPrice' => $s->monthly_price,
            'autoRenew' => (bool) $s->auto_renew,
            'reminderEnabled' => (bool) $s->reminder_enabled,
            'modules' => $s->modules ?? [],
            'restaurants' => $restaurantsByBrand[$s->brand_id] ?? [],
        ];
    }

    /** Restaurant-row variant carrying restaurant/brand display names. */
    public function presentWithNames(AsabSubscription $s): array
    {
        return array_merge($this->present($s), [
            'restaurantName' => optional(AsabRestaurant::find($s->restaurant_id))->name,
            'brandName' => optional(AsabBrand::find($s->brand_id))->name,
        ]);
    }
}
