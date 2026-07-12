<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\ExceptionService;
use Modules\Admin\Support\AssetEnums;
use Modules\Admin\Support\ExpenseEnums;
use Modules\Admin\Support\ModuleCatalog;
use Modules\Admin\Support\OperationEnums;
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
        // `value` mirrors `key` so consumers expecting either field bind cleanly
        // (FE distribution matrix reads `value`; gating/tenant code reads `key`).
        return $this->listResponse(array_map(
            fn ($m) => ['value' => $m['key']] + $m,
            ModuleCatalog::catalog(),
        ));
    }

    /**
     * GET /lookups/asset-enums — the fixed-assets register vocabulary (SRS §4.2
     * categories, useful-life options, lifecycle + workflow statuses) alongside
     * the expenses VAT rate and invoice-match badges (ACC-2).
     */
    public function assetEnums(): JsonResponse
    {
        return $this->ok(AssetEnums::catalog() + ['expenses' => ExpenseEnums::catalog()]);
    }

    /**
     * GET /lookups/purchase-enums — the purchases vocabulary (ACC-3): order
     * source, per-line match badges and the return-order status labels.
     */
    public function purchaseEnums(): JsonResponse
    {
        return $this->ok(\Modules\Admin\Support\PurchaseEnums::catalog());
    }

    /**
     * GET /lookups/rejection-reasons?moduleKey=sales — the fixed §5.4 list the
     * reject modal must render. Sales adds «تقرير POS مفقود» and
     * «كشف البنك غير مرفق» on top of the generic seven.
     */
    public function rejectionReasons(Request $request): JsonResponse
    {
        $reasons = OperationEnums::rejectionReasons($request->query('moduleKey'));

        return $this->listResponse(array_map(
            fn ($key, $labelAr) => ['key' => $key, 'value' => $key, 'labelAr' => $labelAr],
            array_keys($reasons),
            array_values($reasons),
        ));
    }

    /**
     * GET /lookups/operation-enums — every pipeline enum (status, stages,
     * origin, match, rollup, rejection reasons) with its canonical Arabic
     * label, so no screen hardcodes a label map (SRS §5).
     */
    public function operationEnums(): JsonResponse
    {
        return $this->ok(OperationEnums::catalog());
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
