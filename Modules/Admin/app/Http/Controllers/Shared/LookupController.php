<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\ExceptionService;
use Modules\Branch\Models\Branch;

/**
 * Dropdown lookups (BACKEND_API_SPEC.md §7.7).
 */
class LookupController extends AsabController
{
    public function brands(): JsonResponse
    {
        return $this->listResponse(
            AsabBrand::orderBy('name')->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'abbr' => $b->abbr, 'color' => $b->color])->all()
        );
    }

    public function restaurants(Request $request): JsonResponse
    {
        $q = AsabRestaurant::query();
        if ($brandId = $request->query('brandId')) {
            $q->where('brand_id', $brandId);
        }

        return $this->listResponse($q->orderBy('name')->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'brandId' => $r->brand_id])->all());
    }

    public function branches(Request $request): JsonResponse
    {
        $q = Branch::query();
        if ($restaurantId = $request->query('restaurantId')) {
            $q->where('asab_restaurant_id', $restaurantId);
        }

        return $this->listResponse($q->orderBy('name')->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all());
    }

    public function users(Request $request): JsonResponse
    {
        $q = AsabUser::query()->with('roleAssignments');
        if ($role = $request->query('role')) {
            $q->whereHas('roleAssignments', fn ($r) => $r->where('role_key', $role));
        }

        return $this->listResponse($q->orderBy('name')->get()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'role' => $u->primaryRole(),
        ])->all());
    }

    public function suppliers(Request $request): JsonResponse
    {
        try {
            $q = \Modules\Supplier\Models\Supplier::query();
            $items = $q->orderBy('name')->limit(200)->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name ?? null])->all();
        } catch (\Throwable $e) {
            $items = [];
        }

        return $this->listResponse($items);
    }

    public function items(Request $request): JsonResponse
    {
        try {
            $q = \Modules\Purchase\Models\Item::query();
            $items = $q->limit(500)->get()->map(fn ($i) => ['id' => $i->id, 'name' => $i->name ?? null])->all();
        } catch (\Throwable $e) {
            $items = [];
        }

        return $this->listResponse($items);
    }

    public function employees(Request $request): JsonResponse
    {
        $q = \Modules\Admin\Models\Employee::query();
        if ($branchId = $request->query('branchId')) {
            $q->where('branch_id', $branchId);
        }

        return $this->listResponse(
            $q->orderBy('name')->get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'empNumber' => $e->emp_number])->all()
        );
    }

    public function modules(): JsonResponse
    {
        return $this->listResponse([
            ['key' => 'sales', 'labelAr' => 'المبيعات', 'labelEn' => 'Sales', 'icon' => '💰'],
            ['key' => 'expenses', 'labelAr' => 'المصروفات', 'labelEn' => 'Expenses', 'icon' => '🧾'],
            ['key' => 'purchases', 'labelAr' => 'المشتريات', 'labelEn' => 'Purchases', 'icon' => '🛒'],
            ['key' => 'inventory', 'labelAr' => 'المخزون', 'labelEn' => 'Inventory', 'icon' => '📦'],
            ['key' => 'waste', 'labelAr' => 'الهدر', 'labelEn' => 'Waste', 'icon' => '🗑️'],
            ['key' => 'assets', 'labelAr' => 'الأصول', 'labelEn' => 'Assets', 'icon' => '🏷️'],
            ['key' => 'shifts', 'labelAr' => 'الورديات', 'labelEn' => 'Shifts', 'icon' => '🕐'],
            ['key' => 'employees', 'labelAr' => 'الموظفين', 'labelEn' => 'Employees', 'icon' => '👥'],
            ['key' => 'cash', 'labelAr' => 'النقدية', 'labelEn' => 'Cash', 'icon' => '💵'],
        ]);
    }

    /** Exception-type dropdown metadata (MISSING_Dashboard §3.4). */
    public function exceptions(): JsonResponse
    {
        $rows = [];
        foreach (ExceptionService::TYPES as $value => $meta) {
            $rows[] = [
                'value' => $value,
                'labelAr' => $meta['labelAr'],
                'labelEn' => $meta['labelEn'],
                'defaultSeverity' => $meta['defaultSeverity'],
            ];
        }

        return $this->listResponse($rows);
    }
}
