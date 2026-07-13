<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\NotificationService;
use Modules\Admin\Services\PlanLimitService;
use Modules\Admin\Services\RealtimeBroadcaster;

/**
 * Brands / restaurants / branches management (COMPANY_DASHBOARD_API_SPEC.md §5.1.4).
 * Branches reuse the legacy Modules\Branch table via additive asab_* columns.
 */
class OrgController extends AsabController
{
    public function __construct(
        private readonly PlanLimitService $limits,
        private readonly NotificationService $notifications,
        private readonly RealtimeBroadcaster $rt,
    ) {}

    public function tree(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            [$salesByBranch, $branchesByRestaurant] = $this->branchAggregates($companyId);

            $brands = AsabBrand::where('company_id', $companyId)->with('restaurants')->get();
            $branchCount = 0;
            $restaurantCount = 0;
            $data = $brands->map(function (AsabBrand $b) use (&$branchCount, &$restaurantCount, $branchesByRestaurant, $salesByBranch) {
                $brandSales = 0;
                $brandTarget = 0;
                $brandBranches = 0;
                $restaurants = $b->restaurants->map(function (AsabRestaurant $r) use (&$brandSales, &$brandTarget, &$brandBranches, $branchesByRestaurant, $salesByBranch) {
                    $branches = collect($branchesByRestaurant[$r->id] ?? [])->map(function ($br) use (&$brandSales, &$brandTarget, &$brandBranches, $salesByBranch) {
                        $brandBranches++;
                        $sales = (int) ($salesByBranch[$br->id]['sales'] ?? 0);
                        $target = (int) ($br->asab_monthly_target ?? 0);
                        $brandSales += $sales;
                        $brandTarget += $target;

                        return [
                            'id' => $br->id, 'name' => $br->name, 'city' => $br->location,
                            'managerUserId' => $br->asab_manager_user_id, 'managerName' => $br->manager,
                            'salesMonthHalalas' => $sales, 'expensesMonthHalalas' => (int) ($salesByBranch[$br->id]['expenses'] ?? 0),
                            'targetHalalas' => $target, 'pctOfTarget' => $target > 0 ? (int) round($sales / $target * 100) : 0,
                            'status' => $br->status ?? 'active',
                            // CMP-4 badge: pending_review branches render distinctly until approved.
                            'reviewStatus' => $br->asab_review_status ?? 'approved',
                        ];
                    })->all();

                    return ['id' => $r->id, 'name' => $r->name, 'branches' => $branches];
                })->all();
                $restaurantCount += count($restaurants);
                $branchCount += $brandBranches;

                return [
                    'id' => $b->id, 'name' => $b->name, 'abbr' => $b->abbr, 'color' => $b->color,
                    'restaurants' => $restaurants, 'totalBranches' => $brandBranches,
                    'totalSalesHalalas' => $brandSales, 'totalTargetHalalas' => $brandTarget,
                    'pctOfTarget' => $brandTarget > 0 ? (int) round($brandSales / $brandTarget * 100) : 0,
                ];
            })->all();

            return $this->listResponse($data, [
                'brandCount' => $brands->count(), 'restaurantCount' => $restaurantCount, 'branchCount' => $branchCount,
                'maxBranches' => $this->limits->planFor($companyId)?->max_branches,
            ]);
        });
    }

    public function storeBrand(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $data = $request->validate([
                'name' => 'required|string|max:200', 'abbr' => 'required|string|max:4', 'color' => 'required|string|max:16',
                'owner' => 'sometimes|string|max:200', 'ownerEmail' => 'sometimes|email',
            ]);
            if (AsabBrand::where('company_id', $companyId)->where('name', $data['name'])->exists()) {
                throw new AsabException('BRAND_NAME_EXISTS', 'Brand name exists', 'اسم العلامة مستخدم', 409);
            }
            $this->limits->assertCanAdd($companyId, 'brands');

            $brand = AsabBrand::create([
                'company_id' => $companyId, 'name' => $data['name'], 'abbr' => $data['abbr'], 'color' => $data['color'],
                'owner' => $data['owner'] ?? null, 'owner_email' => $data['ownerEmail'] ?? null, 'status' => 'active',
            ]);

            return $this->created(['id' => $brand->id, 'name' => $brand->name, 'abbr' => $brand->abbr, 'color' => $brand->color]);
        });
    }

    public function updateBrand(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $brand = AsabBrand::where('company_id', $request->user()->company_id)->findOrFail($id);
            $data = $request->validate(['name' => 'sometimes|string|max:200', 'abbr' => 'sometimes|string|max:4', 'color' => 'sometimes|string|max:16', 'status' => 'sometimes|in:active,inactive']);
            $brand->update($data);

            return $this->ok(['id' => $brand->id, 'name' => $brand->name, 'abbr' => $brand->abbr, 'color' => $brand->color, 'status' => $brand->status]);
        });
    }

    public function destroyBrand(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $brand = AsabBrand::where('company_id', $request->user()->company_id)->withCount('restaurants')->findOrFail($id);
            if ($brand->restaurants_count > 0) {
                throw new AsabException('BRAND_HAS_DEPENDENTS', 'Brand has restaurants/branches', 'العلامة تحتوي على مطاعم/فروع', 409);
            }
            $brand->delete();

            return $this->noContent();
        });
    }

    public function storeRestaurant(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $data = $request->validate(['brandId' => 'required|string', 'name' => 'required|string|max:200', 'city' => 'required|string|max:80']);
            AsabBrand::where('company_id', $companyId)->findOrFail($data['brandId']);
            $this->limits->assertCanAdd($companyId, 'restaurants');

            $r = AsabRestaurant::create(['company_id' => $companyId, 'brand_id' => $data['brandId'], 'name' => $data['name'], 'city' => $data['city'], 'status' => 'active']);

            return $this->created(['id' => $r->id, 'name' => $r->name, 'brandId' => $r->brand_id, 'city' => $r->city]);
        });
    }

    public function updateRestaurant(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $r = AsabRestaurant::where('company_id', $request->user()->company_id)->findOrFail($id);
            $data = $request->validate(['name' => 'sometimes|string|max:200', 'city' => 'sometimes|string|max:80', 'status' => 'sometimes|in:active,inactive']);
            $r->update($data);

            return $this->ok(['id' => $r->id, 'name' => $r->name, 'city' => $r->city, 'status' => $r->status]);
        });
    }

    public function destroyRestaurant(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $r = AsabRestaurant::where('company_id', $request->user()->company_id)->findOrFail($id);
            if ($this->branchQuery($request->user()->company_id)->where('asab_restaurant_id', $r->id)->exists()) {
                throw new AsabException('RESTAURANT_HAS_BRANCHES', 'Restaurant has branches', 'المطعم يحتوي على فروع', 409);
            }
            $r->delete();

            return $this->noContent();
        });
    }

    public function storeBranch(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $data = $request->validate([
                'restaurantId' => 'required|string', 'name' => 'required|string|max:200', 'city' => 'required|string|max:80',
                'managerUserId' => 'sometimes|nullable|string', 'managerName' => 'sometimes|nullable|string|max:200',
                'address' => 'sometimes|nullable|string', 'phone' => 'sometimes|nullable|string|max:32', 'targetHalalas' => 'sometimes|integer',
            ]);
            $restaurant = AsabRestaurant::where('company_id', $companyId)->findOrFail($data['restaurantId']);
            // Pending branches still consume a quota slot (prevents spamming requests).
            $this->limits->assertCanAdd($companyId, 'branches');

            // CMP-4: a tenant-created branch is inactive + pending_review until a
            // platform admin approves it («سيظهر بعد مراجعة الإدارة»).
            $branch = \Modules\Branch\Models\Branch::create([
                'name' => $data['name'], 'location' => $data['address'] ?? $data['city'],
                'manager' => $data['managerName'] ?? null, 'status' => 'inactive',
                'asab_review_status' => 'pending_review',
                'asab_company_id' => $companyId, 'asab_brand_id' => $restaurant->brand_id, 'asab_restaurant_id' => $restaurant->id,
                'asab_manager_user_id' => $data['managerUserId'] ?? null,
                'asab_monthly_target' => $data['targetHalalas'] ?? null,
            ]);
            // Notify platform admins that a request awaits review.
            foreach (AsabUserRole::where('role_key', 'admin')->pluck('user_id') as $adminId) {
                $this->notifications->push($adminId, 'branch.review_requested', 'طلب فرع جديد بانتظار المراجعة', $branch->name, null, ['type' => 'branch', 'id' => $branch->id]);
            }
            $this->rt->branchChanged($companyId, 'created', $branch->id, $branch->name);

            return $this->created([
                'id' => $branch->id, 'name' => $branch->name, 'city' => $branch->city,
                'status' => 'pending_review', 'reviewStatus' => 'pending_review',
                'messageAr' => 'سيظهر بعد مراجعة الإدارة',
            ]);
        });
    }

    public function updateBranch(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $branch = $this->branchQuery($request->user()->company_id)->findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200', 'manager' => 'sometimes|nullable|string|max:200',
                'status' => 'sometimes|in:active,inactive', 'address' => 'sometimes|nullable|string', 'city' => 'sometimes|nullable|string|max:80',
                'targetHalalas' => 'sometimes|integer|min:0',
            ]);
            $attrs = array_filter([
                'name' => $data['name'] ?? null, 'manager' => $data['manager'] ?? null,
                'status' => $data['status'] ?? null, 'location' => $data['address'] ?? $data['city'] ?? null,
                'asab_monthly_target' => $data['targetHalalas'] ?? null,
            ], fn ($v) => $v !== null);
            $branch->update($attrs);
            $this->rt->branchChanged($request->user()->company_id, 'updated', $branch->id, $branch->name);

            return $this->ok(['id' => $branch->id, 'name' => $branch->name, 'status' => $branch->status]);
        });
    }

    public function destroyBranch(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $branch = $this->branchQuery($request->user()->company_id)->findOrFail($id);
            $open = Operation::where('company_id', $request->user()->company_id)->where('branch_id', $branch->id)
                ->whereIn('status', [Operation::STATUS_PENDING, Operation::STATUS_APPROVED])->exists();
            if ($open) {
                throw new AsabException('BRANCH_HAS_OPEN_OPERATIONS', 'Branch has open operations', 'يوجد عمليات مفتوحة للفرع', 409);
            }
            $branch->delete();

            return $this->noContent();
        });
    }

    public function transferManager(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $companyId = $request->user()->company_id;
            $branch = $this->branchQuery($companyId)->findOrFail($id);
            $data = $request->validate(['newManagerUserId' => 'required|string', 'note' => 'sometimes|string|max:255']);

            // Zero-trust: the new manager must be an active member of THIS company.
            $member = CompanyUser::where('company_id', $companyId)
                ->where('user_id', $data['newManagerUserId'])->where('status', 'active')->first();
            if (! $member) {
                throw new AsabException('INVALID_ROLE_SCOPE', 'Manager must be an active member of this company', 'المدير يجب أن يكون عضواً نشطاً في الشركة', 422);
            }
            $newManager = AsabUser::find($data['newManagerUserId']);

            DB::transaction(function () use ($branch, $member, $data, $newManager) {
                $branch->update([
                    'asab_manager_user_id' => $data['newManagerUserId'],
                    'manager' => $newManager?->name ?? $branch->manager,
                ]);
                // Move the member's home branch and their branch-role data scope so
                // ResolveTenant resolves the transferred branch for the new manager.
                $member->update(['branch_id' => $branch->id]);
                AsabUserRole::where('user_id', $data['newManagerUserId'])->where('role_key', 'branch')
                    ->update(['scope' => 'branch', 'branch_ids' => [$branch->id]]);
            });
            $this->rt->branchChanged($companyId, 'updated', $branch->id, $branch->name);

            return $this->ok(['id' => $branch->id, 'managerUserId' => $branch->asab_manager_user_id]);
        });
    }

    private function branchQuery(string $companyId)
    {
        return \Modules\Branch\Models\Branch::where('asab_company_id', $companyId);
    }

    /** @return array{0: array, 1: array} [salesByBranch, branchesByRestaurant] */
    private function branchAggregates(string $companyId): array
    {
        $branches = $this->branchQuery($companyId)->get(['id', 'name', 'location', 'status', 'manager', 'asab_manager_user_id', 'asab_restaurant_id', 'asab_monthly_target', 'asab_review_status']);
        $byRestaurant = $branches->groupBy('asab_restaurant_id')->map(fn ($g) => $g->all())->all();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString().' 23:59:59';
        $sums = Operation::where('company_id', $companyId)->whereBetween('operation_date', [$from, $to])
            ->whereIn('module_key', ['sales', 'expenses'])
            ->selectRaw('branch_id, module_key, SUM(amount) as total')->groupBy('branch_id', 'module_key')->get();

        $salesByBranch = [];
        foreach ($sums as $row) {
            $salesByBranch[$row->branch_id][$row->module_key === 'sales' ? 'sales' : 'expenses'] = (int) $row->total;
        }

        return [$salesByBranch, $byRestaurant];
    }
}
