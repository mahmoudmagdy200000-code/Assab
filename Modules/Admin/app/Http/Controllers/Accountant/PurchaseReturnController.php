<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Support\PurchaseEnums;
use Modules\Admin\Support\TenantContext;
use Modules\Branch\Models\Branch;

/**
 * T06.9 — ACC-3 «المرتجعات». A read-only window onto the legacy mobile
 * `return_orders`, scoped to the accountant's legacy branch set so one company
 * never reads another's returns. Write actions stay in the mobile world (v1).
 */
class PurchaseReturnController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            if (! class_exists(\Modules\Purchase\Models\ReturnOrder::class)) {
                return $this->listResponse([], ['total' => 0]);
            }

            $request->validate([
                'status' => 'sometimes|string|max:32',
                'branchId' => 'sometimes|string',
                'dateFrom' => 'sometimes|date',
                'dateTo' => 'sometimes|date',
                'page' => 'sometimes|integer|min:1',
                'pageSize' => 'sometimes|integer|min:1|max:100',
            ]);

            $allowed = app(\Modules\Admin\Services\TenantBranchResolver::class)
                ->legacyBranchIds(app(TenantContext::class));

            $q = \Modules\Purchase\Models\ReturnOrder::query()
                ->with(['purchaseOrder:id,order_number'])
                ->orderByDesc('return_date');

            // Fail closed: a non-admin caller with no resolvable branch set sees nothing.
            if ($allowed !== null) {
                if ($allowed === []) {
                    return $this->paginated(
                        \Modules\Purchase\Models\ReturnOrder::query()->whereRaw('1 = 0')
                            ->paginate((int) $request->query('pageSize', 20)),
                        [],
                    );
                }
                $q->whereIn('branch_id', $allowed);
            }

            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            if ($branchId = $request->query('branchId')) {
                $this->assertBranchAssigned($branchId);
                $q->where('branch_id', $branchId);
            }
            if ($from = $request->query('dateFrom')) {
                $q->whereDate('return_date', '>=', $from);
            }
            if ($to = $request->query('dateTo')) {
                $q->whereDate('return_date', '<=', $to);
            }

            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = $q->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            $branchNames = Branch::whereIn('id', $p->getCollection()->pluck('branch_id')->filter()->unique())->pluck('name', 'id');
            $supplierNames = AsabSupplier::whereIn('id', $p->getCollection()->pluck('supplier_id')->filter()->unique())->pluck('name', 'id');

            return $this->paginated($p, $p->getCollection()->map(fn ($r) => [
                'id' => $r->id,
                'returnNumber' => $r->return_number,
                'orderNumber' => $r->purchaseOrder?->order_number,
                'supplierId' => $r->supplier_id,
                'supplierName' => $supplierNames[$r->supplier_id] ?? null,
                'branchId' => $r->branch_id,
                'branchName' => $branchNames[$r->branch_id] ?? null,
                'returnDate' => optional($r->return_date)->toDateString(),
                'status' => $this->statusValue($r->status),
                'statusLabelAr' => PurchaseEnums::returnStatusLabelAr($this->statusValue($r->status)),
                'totalReturnAmountHalalas' => $this->toHalalas($r->total_return_amount),
                'refundAmountHalalas' => $this->toHalalas($r->refund_amount),
                'itemCount' => $r->items()->count(),
            ])->all());
        });
    }

    /** `status` is cast to a ReturnStatus enum on the legacy model; take its value. */
    private function statusValue(mixed $status): ?string
    {
        return $status instanceof \BackedEnum ? $status->value : $status;
    }

    private function toHalalas(mixed $sar): int
    {
        return (int) round(((float) $sar) * 100);
    }
}
