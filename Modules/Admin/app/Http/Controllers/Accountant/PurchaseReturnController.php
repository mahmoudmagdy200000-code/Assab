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
                ->with(['purchaseOrder:id,order_number', 'supplier:id,name'])
                ->withCount('items')
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
                'supplierName' => $r->supplier?->name ?? $supplierNames[$r->supplier_id] ?? null,
                'branchId' => $r->branch_id,
                'branchName' => $branchNames[$r->branch_id] ?? null,
                'returnDate' => optional($r->return_date)->toDateString(),
                'status' => $this->statusValue($r->status),
                'statusLabelAr' => PurchaseEnums::returnStatusLabelAr($this->statusValue($r->status)),
                'totalReturnAmountHalalas' => $this->toHalalas($r->total_return_amount),
                'refundAmountHalalas' => $this->toHalalas($r->refund_amount),
                'itemCount' => (int) ($r->items_count ?? 0),
                'financialStatus' => $r->financial_status ?? 'pending_review',
                'reviewedByAccountantAt' => optional($r->reviewed_by_accountant_at)->toISOString(),
                'approvedByHeadAt' => optional($r->approved_by_head_at)->toISOString(),
                'financialNotes' => $r->financial_notes,
                'handedOverAt' => optional($r->handed_over_at)->toISOString(),
            ])->all());
        });
    }

    /**
     * T3.5 / PUR-04 / DEC-02: Accountant financial review of purchase return value.
     */
    public function review(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['notes' => 'sometimes|nullable|string|max:1000']);
            $allowed = app(\Modules\Admin\Services\TenantBranchResolver::class)->legacyBranchIds(app(TenantContext::class));

            $q = \Modules\Purchase\Models\ReturnOrder::query()->whereKey($id);
            if ($allowed !== null) {
                $q->whereIn('branch_id', $allowed);
            }
            $return = $q->firstOrFail();

            $return->update([
                'financial_status' => 'reviewed_by_accountant',
                'reviewed_by_accountant_id' => $request->user()->id,
                'reviewed_by_accountant_at' => now(),
                'financial_notes' => $data['notes'] ?? $return->financial_notes,
            ]);

            return $this->ok($return->fresh());
        });
    }

    /**
     * T3.5 / PUR-04 / DEC-02: Head accountant final approval of purchase return.
     * Enforces strict sequence gate: Head cannot approve before Accountant review (409).
     */
    public function finalApprove(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['notes' => 'sometimes|nullable|string|max:1000']);
            $allowed = app(\Modules\Admin\Services\TenantBranchResolver::class)->legacyBranchIds(app(TenantContext::class));

            $q = \Modules\Purchase\Models\ReturnOrder::query()->whereKey($id);
            if ($allowed !== null) {
                $q->whereIn('branch_id', $allowed);
            }
            $return = $q->firstOrFail();

            if ($return->financial_status !== 'reviewed_by_accountant') {
                throw new \Modules\Admin\Exceptions\AsabException(
                    'FINANCIAL_REVIEW_REQUIRED',
                    'Head accountant cannot approve return before accountant review.',
                    'لا يمكن اعتماد المرتجع ماليًا قبل مراجعة المحاسب',
                    409
                );
            }

            $return->update([
                'financial_status' => 'approved_by_head',
                'approved_by_head_id' => $request->user()->id,
                'approved_by_head_at' => now(),
                'financial_notes' => $data['notes'] ?? $return->financial_notes,
            ]);

            return $this->ok($return->fresh());
        });
    }

    /**
     * T3.5 / PUR-04: Head accountant sends return back to accountant for review.
     */
    public function returnForReview(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['notes' => 'sometimes|nullable|string|max:1000']);
            $allowed = app(\Modules\Admin\Services\TenantBranchResolver::class)->legacyBranchIds(app(TenantContext::class));

            $q = \Modules\Purchase\Models\ReturnOrder::query()->whereKey($id);
            if ($allowed !== null) {
                $q->whereIn('branch_id', $allowed);
            }
            $return = $q->firstOrFail();

            $return->update([
                'financial_status' => 'pending_review',
                'financial_notes' => $data['notes'] ?? $return->financial_notes,
            ]);

            return $this->ok($return->fresh());
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
