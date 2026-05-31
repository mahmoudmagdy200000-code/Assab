<?php

namespace Modules\Admin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabRestaurant;
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
