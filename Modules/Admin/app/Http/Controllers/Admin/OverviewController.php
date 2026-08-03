<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Services\AsabSubscriptionService;

class OverviewController extends AsabController
{
    public function index(): JsonResponse
    {
        $brands = AsabBrand::all();
        $expiring = $brands->whereIn('sub_status', ['warning', 'danger', 'expired']);

        return $this->ok([
            'kpis' => [
                'brandCount' => $brands->count(),
                'restaurantCount' => AsabRestaurant::count(),
                'branchCount' => $this->legacyBranchCount(),
                'activeUserCount' => AsabUser::where('status', 'active')->count(),
                'brandsNeedingRenewal' => $expiring->count(),
                'uptime' => '99.9%',
            ],
            'brandHierarchy' => $brands->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'abbr' => $b->abbr,
                'color' => $b->color,
                'restaurantCount' => AsabRestaurant::where('brand_id', $b->id)->count(),
                'branchCount' => 0,
                'plan' => $b->plan,
                'subStatus' => $b->sub_status,
                'daysLeft' => AsabSubscriptionService::daysUntil($b->expires) ?? $b->days_left,
            ])->values()->all(),
            'expiringBrands' => $expiring->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name, 'abbr' => $b->abbr,
                'color' => $b->color, 'subStatus' => $b->sub_status,
                'daysLeft' => AsabSubscriptionService::daysUntil($b->expires) ?? $b->days_left,
            ])->values()->all(),
            'accountantsByRole' => $this->roleCounts(),
        ]);
    }

    /** Reuse the legacy Branch module rather than duplicating it. */
    private function legacyBranchCount(): int
    {
        try {
            return \Modules\Branch\Models\Branch::count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function roleCounts(): array
    {
        $counts = AsabUserRole::all()->groupBy('role_key')->map->count();

        return [
            'محاسب' => $counts['accountant'] ?? 0,
            'رئيس حسابات' => $counts['head'] ?? 0,
            'مدير فرع' => $counts['branch'] ?? 0,
            'أدمن' => $counts['admin'] ?? 0,
        ];
    }
}
