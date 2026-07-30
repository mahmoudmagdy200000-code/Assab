<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Models\Asset;

/**
 * Mobile register of the DASHBOARD-owned assets of the manager's branch
 * (meeting 2026-07-30: Excel-uploaded assets — Serial/Zone/Category/Total —
 * never appeared in the app because they live in asab_assets, not
 * fixed_assets). Read-only projection; ids share the legacy branches.id key
 * space so no translation is needed.
 */
class AssetRegisterController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $manager = auth()->user();

        if (! $manager || ! $manager->branch_id) {
            return $this->errorResponse('Branch not assigned', 400);
        }

        // withoutGlobalScope: the mobile request has no asab tenant context;
        // branch_id is the tenant boundary here and must never be dropped.
        $assets = Asset::withoutGlobalScope('tenant')
            ->where('branch_id', $manager->branch_id)
            ->when($request->input('search'), function ($q, $search) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                    ->orWhere('public_id', 'like', "%{$search}%")
                    ->orWhere('serial', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        $assets->getCollection()->transform(fn (Asset $a) => [
            'id' => $a->id,
            'public_id' => $a->public_id ?? '',
            'name' => $a->name ?? '',
            'category' => $a->category ?? '',
            'serial' => $a->serial ?? '',
            'zone' => $a->zone ?? '',
            'quantity' => (int) ($a->quantity ?? 1),
            'qty_excellent' => (int) ($a->qty_excellent ?? 0),
            'qty_maintenance' => (int) ($a->qty_maintenance ?? 0),
            'qty_problem' => (int) ($a->qty_problem ?? 0),
            'custodian' => $a->custodian ?? '',
            'status' => $a->status ?? '',
            // asab_assets stores halalas — the mobile world speaks SAR.
            'cost' => round(((int) $a->cost) / 100, 2),
            'book_value' => round(((int) $a->book_value) / 100, 2),
            'received_at' => $a->received_at?->format('Y-m-d H:i:s'),
        ]);

        return $this->paginatedResponse($assets, 'Branch asset register retrieved successfully');
    }
}
