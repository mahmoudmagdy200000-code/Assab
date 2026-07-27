<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Services\NotificationService;
use Modules\Branch\Models\Branch;

/**
 * Admin branch management. Reuses the existing Branch module table, enriched with
 * the ASAB hierarchy columns (asab_company_id/brand_id/restaurant_id).
 */
class BranchController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = Branch::query();
            if ($restaurantId = $request->query('restaurantId')) {
                $q->where('asab_restaurant_id', $restaurantId);
            }
            if ($brandId = $request->query('brandId')) {
                $q->where('asab_brand_id', $brandId);
            }
            $branches = $q->orderBy('name')->get();

            return $this->listResponse($branches->map(fn ($b) => $this->present($b))->all());
        });
    }

    /**
     * GET /admin/brands/{brandId}/branches?linked=false|true|all
     * (alias: GET /admin/brands/{brandId}/unlinked-branches)
     *
     * The brand tree walks restaurants, so a branch with `asab_restaurant_id`
     * NULL appears NOWHERE in it — including on the screen that warns the
     * catalog reached zero branches (BE-fixes 2026-07-26 §6). With no id in any
     * response, the «اربط الفروع» action had nothing to PATCH; this is the
     * missing read side of that repair path.
     *
     * `linked=false` returns both kinds of candidate:
     *  - `linkage=brand`  → brand column stamped, restaurant missing
     *  - `linkage=orphan` → no hierarchy columns at all (pre-dashboard / mobile)
     *
     * An orphan carries no brand, so it is offered to every brand whose company
     * matches (or that has no company stamped either) — that IS the repair. The
     * link itself resolves all three columns from the restaurant, see update().
     */
    public function brandBranches(Request $request, string $brandId): JsonResponse
    {
        return $this->run(function () use ($request, $brandId) {
            $brand = AsabBrand::withoutGlobalScope('tenant')->findOrFail($brandId);
            $this->assertBrandAssigned($brand->id);

            $filter = strtolower((string) $request->input('linked', 'all'));
            $filter = in_array($filter, ['false', '0', 'no'], true) ? 'false'
                : (in_array($filter, ['true', '1', 'yes'], true) ? 'true' : 'all');

            $restaurants = AsabRestaurant::withoutGlobalScope('tenant')
                ->where('brand_id', $brand->id)->orderBy('name')->pluck('name', 'id');
            $restaurantIds = $restaurants->keys()->all();

            $q = Branch::query();
            if ($filter === 'true') {
                $q->whereIn('asab_restaurant_id', $restaurantIds);
            } elseif ($filter === 'false') {
                $q->whereNull('asab_restaurant_id')->where(fn ($w) => $w
                    ->where('asab_brand_id', $brand->id)
                    ->orWhere(fn ($o) => $o->whereNull('asab_brand_id')->where(fn ($c) => $c
                        ->whereNull('asab_company_id')
                        ->when($brand->company_id, fn ($x) => $x->orWhere('asab_company_id', $brand->company_id))
                    ))
                );
            } else {
                $q->where(fn ($w) => $w
                    ->whereIn('asab_restaurant_id', $restaurantIds)
                    ->orWhere('asab_brand_id', $brand->id)
                );
            }

            // Capped: this feeds a picker, never a report.
            $branches = $q->orderBy('name')->limit(500)->get();

            $rows = $branches->map(fn (Branch $b) => $this->present($b) + [
                'restaurantName' => $restaurants[$b->asab_restaurant_id] ?? null,
                'linkage' => $b->asab_restaurant_id ? 'linked' : ($b->asab_brand_id ? 'brand' : 'orphan'),
            ])->values()->all();

            return $this->listResponse($rows, [
                'brandId' => $brand->id,
                'linked' => $filter,
                'total' => count($rows),
                // The picker's other half — no second call to build the dropdown.
                'restaurants' => $restaurants->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all(),
            ]);
        });
    }

    /** GET /admin/brands/{brandId}/unlinked-branches — alias of brandBranches?linked=false. */
    public function brandUnlinkedBranches(Request $request, string $brandId): JsonResponse
    {
        return $this->brandBranches($request->merge(['linked' => 'false']), $brandId);
    }

    public function store(Request $request, string $restaurantId): JsonResponse
    {
        return $this->run(function () use ($request, $restaurantId) {
            $restaurant = AsabRestaurant::findOrFail($restaurantId);
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'manager' => 'nullable|string|max:200',
                'managerUserId' => 'nullable|string',
                'city' => 'nullable|string|max:80',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:32',
            ]);
            if (! empty($data['managerUserId'])) {
                $this->assertManagerAssignable($data['managerUserId']);
            }

            $branch = DB::transaction(fn () => Branch::create([
                'name' => $data['name'],
                'manager' => $data['manager'] ?? null,
                'asab_manager_user_id' => $data['managerUserId'] ?? null,
                'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => 'active',
                'is_active' => true,
                'asab_restaurant_id' => $restaurant->id,
                'asab_brand_id' => $restaurant->brand_id,
                'asab_company_id' => $restaurant->company_id,
            ]));

            return $this->created($this->present($branch));
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $branch = Branch::findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200',
                'manager' => 'sometimes|string|max:200',
                'managerUserId' => 'sometimes|string',
                'city' => 'sometimes|string|max:80',
                'address' => 'sometimes|string',
                'phone' => 'sometimes|string|max:32',
                'status' => 'sometimes|in:active,suspended',
                // Attach an EXISTING branch to a restaurant. Only `store()` used
                // to set the hierarchy columns, so a branch that predates the
                // dashboard (or came from the mobile side) could never be linked
                // — and an unlinked branch is invisible to every brand-scoped
                // write, most visibly the catalog write-through that feeds the
                // app's item list (reported 2026-07-26).
                'restaurantId' => 'sometimes|uuid',
            ]);
            if (! empty($data['managerUserId'])) {
                $this->assertManagerAssignable($data['managerUserId'], $branch->id);
            }
            // Resolved (not taken from the body) so brand/company always agree
            // with the restaurant — the three columns are read as one unit.
            $restaurant = isset($data['restaurantId'])
                ? AsabRestaurant::withoutGlobalScope('tenant')->findOrFail($data['restaurantId'])
                : null;

            DB::transaction(fn () => $branch->update(array_filter([
                'name' => $data['name'] ?? null,
                'manager' => $data['manager'] ?? null,
                'asab_manager_user_id' => $data['managerUserId'] ?? null,
                'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => $data['status'] ?? null,
                'asab_restaurant_id' => $restaurant?->id,
                'asab_brand_id' => $restaurant?->brand_id,
                'asab_company_id' => $restaurant?->company_id,
            ], fn ($v) => $v !== null)));

            return $this->ok($this->present($branch->fresh()));
        });
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            Branch::findOrFail($id)->delete();

            return $this->noContent();
        });
    }

    /** GET /admin/branch-requests?status=pending_review|approved|rejected|all — CMP-4 review queue. */
    public function branchRequests(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $status = $request->query('status', 'pending_review');
            $q = Branch::query()->whereNotNull('asab_review_status');
            if ($status !== 'all') {
                $q->where('asab_review_status', $status);
            }
            $branches = $q->orderByDesc('created_at')->limit(500)->get();

            return $this->listResponse($branches->map(fn (Branch $b) => $this->present($b) + [
                'reviewStatus' => $b->asab_review_status,
                'reviewNote' => $b->asab_review_note,
            ])->all());
        });
    }

    /** POST /admin/branch-requests/{id}/approve — flip a pending branch live. */
    public function approveBranchRequest(string $id, NotificationService $notifications): JsonResponse
    {
        return $this->run(function () use ($id, $notifications) {
            $branch = Branch::where('asab_review_status', 'pending_review')->findOrFail($id);
            $branch->update(['asab_review_status' => 'approved', 'status' => 'active', 'is_active' => true]);
            $this->notifyCompany($notifications, $branch, 'branch.approved', 'تمت الموافقة على الفرع', $branch->name);

            return $this->ok($this->present($branch->fresh()) + ['reviewStatus' => 'approved']);
        });
    }

    /** POST /admin/branch-requests/{id}/reject — reject with a reason. */
    public function rejectBranchRequest(Request $request, string $id, NotificationService $notifications): JsonResponse
    {
        return $this->run(function () use ($request, $id, $notifications) {
            $data = $request->validate(['reason' => 'required|string|max:500']);
            $branch = Branch::where('asab_review_status', 'pending_review')->findOrFail($id);
            $branch->update(['asab_review_status' => 'rejected', 'asab_review_note' => $data['reason'], 'status' => 'inactive']);
            $this->notifyCompany($notifications, $branch, 'branch.rejected', 'تم رفض طلب الفرع', $data['reason']);

            return $this->ok($this->present($branch->fresh()) + ['reviewStatus' => 'rejected', 'reviewNote' => $data['reason']]);
        });
    }

    private function notifyCompany(NotificationService $notifications, Branch $branch, string $type, string $title, ?string $body): void
    {
        if ($branch->asab_company_id) {
            $notifications->pushToRole($branch->asab_company_id, 'company-admin', $type, $title, $body, null, ['type' => 'branch', 'id' => $branch->id]);
        }
    }

    /**
     * Manager assignment rules (client meeting): the user must hold the branch
     * role, and a user may manage at most ONE branch.
     */
    private function assertManagerAssignable(string $userId, ?string $exceptBranchId = null): void
    {
        if (! AsabUserRole::where('user_id', $userId)->where('role_key', 'branch')->exists()) {
            throw new AsabException('MANAGER_ROLE_INVALID', 'User does not have the branch manager role', 'المستخدم ليس بدور مدير فرع', 422);
        }

        $alreadyManaging = Branch::where('asab_manager_user_id', $userId)
            ->when($exceptBranchId, fn ($q) => $q->where('id', '!=', $exceptBranchId))
            ->exists();
        if ($alreadyManaging) {
            throw new AsabException('MANAGER_ALREADY_ASSIGNED', 'User already manages another branch', 'المستخدم مدير لفرع آخر بالفعل', 422);
        }
    }

    private function present(Branch $b): array
    {
        return [
            'id' => $b->id,
            'restaurantId' => $b->asab_restaurant_id,
            'brandId' => $b->asab_brand_id,
            'companyId' => $b->asab_company_id,
            'name' => $b->name,
            'manager' => $b->manager,
            'managerUserId' => $b->asab_manager_user_id,
            'address' => $b->address,
            'city' => $b->city,
            'phone' => $b->phone,
            'status' => $b->status ?? ($b->is_active ? 'active' : 'suspended'),
        ];
    }
}
