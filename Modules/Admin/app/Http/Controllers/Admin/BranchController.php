<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
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
            ]);
            if (! empty($data['managerUserId'])) {
                $this->assertManagerAssignable($data['managerUserId'], $branch->id);
            }
            DB::transaction(fn () => $branch->update(array_filter([
                'name' => $data['name'] ?? null,
                'manager' => $data['manager'] ?? null,
                'asab_manager_user_id' => $data['managerUserId'] ?? null,
                'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => $data['status'] ?? null,
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
