<?php

namespace Modules\Admin\Services;

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
    /** Extend a subscription by N months from the later of now / current expiry. */
    public function renew(AsabSubscription $sub, int $months = 12): AsabSubscription
    {
        return DB::transaction(function () use ($sub, $months) {
            $base = $sub->expires_at && $sub->expires_at->isFuture() ? $sub->expires_at : now();
            $expires = $base->copy()->addMonths(max(1, $months));
            $sub->update([
                'status' => 'active',
                'expires_at' => $expires,
                'days_left' => (int) now()->diffInDays($expires),
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

    /** @return array<string, mixed> */
    public function present(AsabSubscription $s): array
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
