<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
use Modules\Admin\Services\NotificationService;
use Modules\Admin\Services\OperationFactory;

/**
 * Company-scoped Branch Manager surface — NEW endpoints beyond the shared
 * BranchDashboardController (COMPANY_DASHBOARD_API_SPEC.md §5.4).
 */
class BranchCompanyController extends AsabController
{
    public function __construct(
        private readonly OperationFactory $factory,
        private readonly NotificationService $notifications,
    ) {}

    /** Resolve the branch the current branch-manager owns. */
    private function branchId(Request $request): ?string
    {
        $assignment = $request->user()->roleAssignments->firstWhere('role_key', 'branch');

        return $assignment?->branch_ids[0] ?? null;
    }

    /** Combined daily upload: creates a sales and/or expenses pending Operation (§5.4). */
    public function upload(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'sales' => 'sometimes|array', 'sales.totalHalalas' => 'sometimes|integer|min:0',
                'expenses' => 'sometimes|array', 'expenses.totalHalalas' => 'sometimes|integer|min:0',
            ]);
            $branchId = $this->branchId($request);
            $ops = [];
            if (! empty($data['sales'])) {
                $op = $this->factory->createFromUpload('sales', $data['sales'], $request->user(), $branchId, (int) ($data['sales']['totalHalalas'] ?? 0));
                $ops[] = ['id' => $op->id, 'publicId' => $op->public_id, 'moduleKey' => 'sales', 'status' => $op->status];
            }
            if (! empty($data['expenses'])) {
                $op = $this->factory->createFromUpload('expenses', $data['expenses'], $request->user(), $branchId, (int) ($data['expenses']['totalHalalas'] ?? 0));
                $ops[] = ['id' => $op->id, 'publicId' => $op->public_id, 'moduleKey' => 'expenses', 'status' => $op->status];
            }

            return $this->created(['operations' => $ops]);
        });
    }

    public function itemsCount(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'counts' => 'required|array|min:1',
                'counts.*.inventoryItemId' => 'required|string',
                'counts.*.actualQty' => 'required|numeric',
            ]);
            $op = $this->factory->createFromUpload('inventory', ['counts' => $data['counts'], 'countType' => 'daily'], $request->user(), $this->branchId($request));

            return $this->created(['id' => $op->id, 'publicId' => $op->public_id, 'status' => $op->status]);
        });
    }

    public function purchaseRequests(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $rows = Operation::where('company_id', $request->user()->company_id)
                ->where('module_key', 'purchases')->where('origin', 'mobile')
                ->when($this->branchId($request), fn ($q, $b) => $q->where('branch_id', $b))
                ->orderByDesc('operation_date')->limit(100)->get()
                ->map(fn (Operation $o) => [
                    'id' => $o->id, 'item' => $o->payload['item'] ?? null, 'qty' => $o->payload['qty'] ?? null,
                    'unit' => $o->payload['unit'] ?? null, 'status' => $o->status, 'urgency' => $o->payload['urgency'] ?? 'normal',
                    'date' => optional($o->operation_date)->toIso8601String(),
                ])->all();

            return $this->listResponse($rows);
        });
    }

    public function storePurchaseRequest(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'item' => 'required|string|max:200', 'qty' => 'required|numeric|min:0', 'unit' => 'required|string|max:16',
                'urgency' => 'sometimes|in:normal,urgent', 'notes' => 'sometimes|nullable|string',
            ]);
            $op = $this->factory->createFromUpload('purchases', [
                'item' => $data['item'], 'qty' => $data['qty'], 'unit' => $data['unit'],
                'urgency' => $data['urgency'] ?? 'normal', 'notes' => $data['notes'] ?? null, 'kind' => 'branch_request',
            ], $request->user(), $this->branchId($request));
            $this->notifications->pushToRole($request->user()->company_id, 'procurement', 'purchase.request', 'طلب شراء جديد من فرع', $data['item']);

            return $this->created(['id' => $op->id, 'publicId' => $op->public_id, 'status' => $op->status]);
        });
    }

    public function requestNewSupplier(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:200', 'category' => 'sometimes|nullable|string|max:80',
                'contactPhone' => 'sometimes|nullable|string|max:32', 'reason' => 'sometimes|nullable|string',
            ]);
            $this->notifications->pushToRole($request->user()->company_id, 'procurement', 'supplier.review_request',
                'طلب اعتماد مورد جديد', $data['name'].' — '.($data['reason'] ?? ''));

            return $this->ok(['requested' => true, 'name' => $data['name']], 202);
        });
    }

    public function activeShift(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $shift = Shift::where('company_id', $request->user()->company_id)
                ->when($this->branchId($request), fn ($q, $b) => $q->where('branch_id', $b))
                ->where('status', 'active')->orderByDesc('started_at')->first();

            return $this->ok($shift ? $this->present($shift) : null);
        });
    }

    public function openShift(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $branchId = $this->branchId($request);
            $data = $request->validate(['cashierEmpNumber' => 'sometimes|nullable|string', 'openingCashHalalas' => 'required|integer|min:0']);

            $exists = Shift::where('company_id', $request->user()->company_id)->where('branch_id', $branchId)->where('status', 'active')->exists();
            if ($exists) {
                throw new AsabException('SHIFT_ALREADY_OPEN', 'A shift is already open for this branch', 'يوجد وردية مفتوحة بالفعل لهذا الفرع', 409);
            }

            $shift = Shift::create([
                'company_id' => $request->user()->company_id, 'branch_id' => $branchId,
                'supervisor_user_id' => $request->user()->id, 'supervisor_name' => $request->user()->name,
                'started_at' => now(), 'status' => 'active', 'cash_expected' => $data['openingCashHalalas'],
            ]);

            return $this->created($this->present($shift));
        });
    }

    private function present(Shift $s): array
    {
        return [
            'id' => $s->id, 'branchId' => $s->branch_id, 'supervisorName' => $s->supervisor_name,
            'startedAt' => optional($s->started_at)->toIso8601String(), 'status' => $s->status,
            'ordersCount' => $s->orders_count, 'salesHalalas' => $s->sales_amount, 'openCashHalalas' => $s->cash_expected,
        ];
    }
}
