<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationService;
use Modules\Admin\Services\WasteApprovalService;
use Modules\Admin\Support\WasteEnums;
use Modules\Branch\Models\Branch;

/**
 * Accountant waste & damage review (SRS ACC-5). Waste records are operations
 * with module_key=waste; products live in payload. Approving a record charges
 * «موظف»-responsibility products to the employee ledger (WasteApprovalService).
 */
class WasteController extends AsabController
{
    public function __construct(
        private readonly OperationService $service,
        private readonly WasteApprovalService $waste,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = $this->scopeToAssignedBranches(Operation::where('module_key', 'waste'));
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            }
            if ($search = $request->query('search')) {
                $q->where(fn ($w) => $w->where('public_id', 'like', "%{$search}%")
                    ->orWhere('payload->products', 'like', "%{$search}%"));
            }

            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = $q->orderByDesc('operation_date')->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            $brandNames = $this->brandNamesFor($p->getCollection());

            return $this->paginated(
                $p,
                $p->getCollection()->map(fn (Operation $o) => $this->row($o, $brandNames))->all(),
                ['summary' => $this->summary($request)],
            );
        });
    }

    public function classifyProduct(Request $request, string $entryId, int $productIdx): JsonResponse
    {
        return $this->run(function () use ($request, $entryId, $productIdx) {
            $data = $request->validate([
                'classification' => 'sometimes|in:'.implode(',', WasteEnums::CLASSIFICATION),
                'responsibility' => 'sometimes|in:'.implode(',', WasteEnums::RESPONSIBILITY),
            ]);
            $op = $this->find($entryId);
            $this->service->assertMutable($op, 'لا يمكن تعديل عملية مُغلقة');
            $payload = $op->payload ?? [];
            if (! isset($payload['products'][$productIdx])) {
                return $this->fail('NOT_FOUND', 'Product not found in waste entry', 'المنتج غير موجود', [], 404);
            }
            foreach ($data as $k => $v) {
                $payload['products'][$productIdx][$k] = $v;
            }
            $op->update(['payload' => $payload]);

            return $this->ok(['id' => $op->id, 'product' => $payload['products'][$productIdx]]);
        });
    }

    /**
     * PUT …/products/{idx}/allocations — the «تحميل على موظفين» panel, same
     * contract as sales shortfall: each employee resolved in the op's branch,
     * and for a «موظف» product the amounts must sum to the product value.
     */
    public function allocations(Request $request, string $entryId, int $productIdx): JsonResponse
    {
        return $this->run(function () use ($request, $entryId, $productIdx) {
            $data = $request->validate([
                'empAllocs' => 'required|array|min:1',
                'empAllocs.*.employeeId' => 'sometimes|string',
                'empAllocs.*.empNumber' => 'sometimes|string',
                'empAllocs.*.amountHalalas' => 'required|integer|min:1',
            ]);
            $op = $this->find($entryId);
            $this->service->assertMutable($op, 'لا يمكن تعديل عملية مُغلقة');
            $payload = $op->payload ?? [];
            if (! isset($payload['products'][$productIdx])) {
                return $this->fail('NOT_FOUND', 'Product not found', 'المنتج غير موجود', [], 404);
            }

            $rows = $this->waste->normaliseAllocations($op, $payload['products'][$productIdx], $data['empAllocs']);
            $payload['products'][$productIdx]['empAllocs'] = array_map(
                fn ($r) => ['employeeId' => $r['employeeId'], 'amountHalalas' => $r['amountHalalas']], $rows,
            );
            $op->update(['payload' => $payload]);

            return $this->ok(['id' => $op->id, 'empAllocs' => $rows]);
        });
    }

    public function approve(Request $request, string $entryId): JsonResponse
    {
        return $this->run(fn () => $this->ok([
            'id' => $entryId,
            'status' => $this->waste->approve($this->find($entryId), $request->user())->status,
        ]));
    }

    public function reject(Request $request, string $entryId): JsonResponse
    {
        return $this->run(function () use ($request, $entryId) {
            $data = $request->validate(['reason' => 'required|string|max:500', 'notes' => 'nullable|string']);

            return $this->ok([
                'id' => $entryId,
                'status' => $this->service->reject($this->find($entryId), $request->user(), $data['reason'], $data['notes'] ?? null)->status,
            ]);
        });
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['entryIds' => 'sometimes|array', 'entryIds.*' => 'string', 'branchId' => 'sometimes|string']);
            // Zero-trust: resolve ids through the branch-scoped query so
            // out-of-scope entries are dropped before the approval service.
            $ids = isset($data['entryIds'])
                ? $this->scopeToAssignedBranches(Operation::where('module_key', 'waste'))
                    ->where(fn ($q) => $q->whereIn('id', $data['entryIds'])->orWhereIn('public_id', $data['entryIds']))
                    ->pluck('id')->all()
                : $this->scopeToAssignedBranches(Operation::where('module_key', 'waste'))
                    ->when($data['branchId'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
                    ->where('status', 'pending')->pluck('id')->all();

            return $this->ok($this->waste->bulkApprove($ids, $request->user()));
        });
    }

    /** ACC-5.1/5.2 list row. */
    private function row(Operation $o, array $brandNames): array
    {
        $products = $o->payload['products'] ?? [];

        return [
            'id' => $o->id,
            'publicId' => $o->public_id,
            'branchId' => $o->branch_id,
            'branchName' => $brandNames[$o->branch_id]['branchName'] ?? null,
            'brandId' => $brandNames[$o->branch_id]['brandId'] ?? null,
            'brandName' => $brandNames[$o->branch_id]['brandName'] ?? null,
            'date' => optional($o->operation_date)->toIso8601String(),
            'status' => $o->status,
            'amount' => $o->amount,
            'amountHalalas' => (int) $o->amount,
            'productsCount' => count($products),
            'employeeChargedHalalas' => $this->employeeCharged($products),
            'products' => $products,
        ];
    }

    /** «منه على موظفين» — Σ empAllocs of «موظف» products. */
    private function employeeCharged(array $products): int
    {
        $sum = 0;
        foreach ($products as $p) {
            if (($p['responsibility'] ?? null) === WasteEnums::RESP_EMPLOYEE) {
                $sum += array_sum(array_map(fn ($a) => (int) ($a['amountHalalas'] ?? 0), $p['empAllocs'] ?? []));
            }
        }

        return $sum;
    }

    private function summary(Request $request): array
    {
        $base = $this->scopeToAssignedBranches(Operation::where('module_key', 'waste'));
        if ($branch = $request->query('branchId')) {
            $base->where('branch_id', $branch);
        }
        $monthStart = now()->startOfMonth()->toDateString();

        $charged = 0;
        foreach ((clone $base)->where('status', 'approved')->limit(5000)->get(['payload']) as $o) {
            $charged += $this->employeeCharged($o->payload['products'] ?? []);
        }

        return [
            'total' => (clone $base)->count(),
            'pendingReview' => (clone $base)->where('status', 'pending')->count(),
            'approvedThisMonth' => (clone $base)->where('status', 'approved')->whereDate('operation_date', '>=', $monthStart)->count(),
            'totalLossesHalalas' => (int) (clone $base)->sum('amount'),
            'chargedToEmployeesHalalas' => $charged,
        ];
    }

    /** @return array<string, array{brandId:?string, brandName:?string}> branchId → brand */
    private function brandNamesFor($ops): array
    {
        $branches = Branch::whereIn('id', $ops->pluck('branch_id')->filter()->unique())->get(['id', 'name', 'asab_brand_id']);
        $brandNames = AsabBrand::whereIn('id', $branches->pluck('asab_brand_id')->filter()->unique())->pluck('name', 'id');

        return $branches->mapWithKeys(fn ($b) => [$b->id => [
            'brandId' => $b->asab_brand_id,
            'brandName' => $brandNames[$b->asab_brand_id] ?? null,
            // The row carried the brand only, so a per-branch list had nothing
            // but the branch UUID to show (same defect as the جرد screen).
            'branchName' => $b->name,
        ]])->all();
    }

    private function find(string $id): Operation
    {
        return $this->scopeToAssignedBranches(
            Operation::where('module_key', 'waste')->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id)),
        )->firstOrFail();
    }
}
