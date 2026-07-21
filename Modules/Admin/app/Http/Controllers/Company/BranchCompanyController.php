<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\BranchInventoryList;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
use Modules\Admin\Models\SupplierRequest;
use Modules\Admin\Services\CashierProvisioningService;
use Modules\Admin\Services\NotificationService;
use Modules\Admin\Services\OperationAttachmentService;
use Modules\Admin\Services\OperationFactory;
use Modules\Admin\Services\RealtimeBroadcaster;

/**
 * Company-scoped Branch Manager surface — NEW endpoints beyond the shared
 * BranchDashboardController (COMPANY_DASHBOARD_API_SPEC.md §5.4).
 */
class BranchCompanyController extends AsabController
{
    use \Modules\Admin\Http\Controllers\Concerns\GeneratesEmployeeNumbers;

    public function __construct(
        private readonly OperationFactory $factory,
        private readonly NotificationService $notifications,
        private readonly RealtimeBroadcaster $rt,
        private readonly CashierProvisioningService $cashiers,
        private readonly OperationAttachmentService $attachments,
        private readonly \Modules\Admin\Services\BranchDailyReportsService $dailyReports,
    ) {}

    /** Resolve the branch the current branch-manager owns. */
    private function branchId(Request $request): ?string
    {
        $assignment = $request->user()->roleAssignments->firstWhere('role_key', 'branch');

        return $assignment?->branch_ids[0] ?? null;
    }

    /**
     * Combined daily upload (§5.4). Accepts EITHER the original nested body
     * {sales:{totalHalalas},expenses:{totalHalalas}} OR the flat doc body
     * {reportType, date, salesHalalas?, shift?, expensesHalalas?, expenseNote?}
     * plus multipart `attachments[]`. Branches on reportType to create the right
     * Operation; uploaded files are persisted via the Attachment model and linked
     * to the created operation. Response is a superset of {operations:[...]}.
     */
    public function upload(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $reportType = $request->input('reportType');
            $branchId = $this->branchId($request);

            // Flat doc body: a single typed report keyed by reportType.
            if ($reportType !== null) {
                return $this->uploadFlat($request, $branchId, $reportType);
            }

            // Legacy nested body: sales and/or expenses in one call.
            $data = $request->validate([
                'sales' => 'sometimes|array', 'sales.totalHalalas' => 'sometimes|integer|min:0',
                'expenses' => 'sometimes|array', 'expenses.totalHalalas' => 'sometimes|integer|min:0',
            ]);
            $ops = [];
            if (! empty($data['sales'])) {
                $op = $this->factory->createFromUpload('sales', $data['sales'], $request->user(), $branchId, (int) ($data['sales']['totalHalalas'] ?? 0), 'mobile', 'dashboard');
                $ops[] = ['id' => $op->id, 'publicId' => $op->public_id, 'moduleKey' => 'sales', 'status' => $op->status];
            }
            if (! empty($data['expenses'])) {
                $op = $this->factory->createFromUpload('expenses', $data['expenses'], $request->user(), $branchId, (int) ($data['expenses']['totalHalalas'] ?? 0), 'mobile', 'dashboard');
                $ops[] = ['id' => $op->id, 'publicId' => $op->public_id, 'moduleKey' => 'expenses', 'status' => $op->status];
            }

            return $this->created(['operations' => $ops]);
        });
    }

    /** Flat doc-body branch of upload(): one typed report + multipart attachments. */
    private function uploadFlat(Request $request, ?string $branchId, string $reportType): JsonResponse
    {
        // The checklist reportTypes (incl. shift-close → shifts) plus the legacy
        // wider set the platform surface already accepts.
        $allowed = ['sales', 'inventory', 'cash', 'waste', 'purchases', 'expenses', 'shift-close'];
        $request->validate([
            'reportType' => 'required|in:'.implode(',', $allowed),
            'date' => 'sometimes|nullable|date',
            'salesHalalas' => 'sometimes|nullable|integer|min:0',
            'shift' => 'sometimes|nullable|string|max:32',
            'expensesHalalas' => 'sometimes|nullable|integer|min:0',
            'expenseNote' => 'sometimes|nullable|string',
            'attachments' => 'sometimes|array',
            'attachments.*' => 'file|max:10240',
        ]);

        // A sales report without its figure is the empty core of the form —
        // reject it explicitly (INVALID_INPUT) rather than silently store a 0.
        if ($reportType === 'sales' && $request->input('salesHalalas') === null) {
            return $this->fail('INVALID_INPUT', 'salesHalalas is required for a sales report',
                'قيمة المبيعات مطلوبة لتقرير المبيعات', ['salesHalalas' => ['قيمة المبيعات مطلوبة']], 422);
        }

        $salesHalalas = (int) ($request->input('salesHalalas') ?? 0);
        $expensesHalalas = (int) ($request->input('expensesHalalas') ?? 0);
        $amount = $reportType === 'expenses' ? $expensesHalalas : $salesHalalas;

        // shift-close rides the shifts module; every other id is its own module.
        $moduleKey = $this->dailyReports->moduleFor($reportType) ?? $reportType;

        $payload = [
            'date' => $request->input('date'),
            'shift' => $request->input('shift'),
            'salesHalalas' => $salesHalalas,
            'expensesHalalas' => $expensesHalalas,
            'expenseNote' => $request->input('expenseNote'),
        ];

        $op = $this->factory->createFromUpload($moduleKey, $payload, $request->user(), $branchId, $amount, 'mobile', 'dashboard');

        // Persist + link uploaded attachments to the created operation.
        $attachments = $this->attachments->store($request, $op, 'operation');

        return $this->created([
            // Superset: keep the existing `operations` array contract...
            'operations' => [[
                'id' => $op->id, 'publicId' => $op->public_id, 'moduleKey' => $op->module_key, 'status' => $op->status,
            ]],
            // ...and add the flat doc-shaped keys alongside it. `status` is the
            // created operation's pipeline status (pending review) — the branch →
            // accountant handoff — not an upload-success flag.
            'uploadId' => $op->public_id,
            'reportType' => $reportType,
            'status' => $op->status,
            'createdOperationId' => $op->id,
            'uploadedAt' => optional($op->submitted_at)->toIso8601String() ?? now()->toIso8601String(),
            'attachments' => $attachments,
        ]);
    }

    public function itemsCount(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'counts' => 'required|array|min:1',
                'counts.*.inventoryItemId' => 'required|string',
                'counts.*.actualQty' => 'required|numeric',
            ]);
            $branchId = $this->branchId($request);

            // BRM-4.2 — only items on this branch's configured daily list
            // (fallback: the brand's sales catalog) may be counted.
            $allowed = $this->countableItemIds($request, $branchId);
            $submitted = collect($data['counts'])->pluck('inventoryItemId');
            $foreign = $submitted->reject(fn ($id) => $allowed->contains($id))->values();
            if ($foreign->isNotEmpty()) {
                return $this->fail('INVALID_ITEM_IDS', 'Some items are not on this branch list',
                    'بعض الأصناف ليست ضمن قائمة جرد الفرع', ['unknown' => $foreign->all()], 422);
            }

            // One daily count per branch per day.
            $already = Operation::where('company_id', $request->user()->company_id)
                ->where('branch_id', $branchId)->where('module_key', 'inventory')
                ->where('payload->countType', 'daily')->whereDate('operation_date', today())->exists();
            if ($already) {
                throw new AsabException('DAILY_COUNT_EXISTS', 'A daily count already exists for today',
                    'تم تسجيل جرد يومي لهذا الفرع اليوم', 409);
            }

            // Capture expected qty per line for the accountant's variance view.
            $expected = InventoryCatalogItem::whereIn('id', $submitted)->pluck('expected_qty', 'id');
            $counts = collect($data['counts'])->map(fn ($c) => $c + [
                'expectedQty' => isset($expected[$c['inventoryItemId']]) ? (float) $expected[$c['inventoryItemId']] : null,
            ])->all();

            $op = $this->factory->createFromUpload('inventory',
                ['counts' => $counts, 'countType' => 'daily'], $request->user(), $branchId, 0, 'mobile', 'dashboard');

            return $this->created(['id' => $op->id, 'publicId' => $op->public_id, 'status' => $op->status]);
        });
    }

    /** Catalog item ids countable for a branch: its daily list, else the brand sales catalog. */
    private function countableItemIds(Request $request, ?string $branchId): \Illuminate\Support\Collection
    {
        $listIds = BranchInventoryList::where('branch_id', $branchId)->pluck('catalog_item_id');
        if ($listIds->isNotEmpty()) {
            return $listIds;
        }
        $brandId = \Modules\Branch\Models\Branch::whereKey($branchId)
            ->where('asab_company_id', $request->user()->company_id)->value('asab_brand_id');

        return $brandId
            ? InventoryCatalogItem::where('brand_id', $brandId)->where('type', InventoryCatalogItem::TYPE_SALES_ITEM)->pluck('id')
            : collect();
    }

    public function purchaseRequests(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            // Filter by the branch-request marker (kind), not origin — decoupled
            // from the origin enum so it survives dashboard-channel submissions.
            $rows = Operation::where('company_id', $request->user()->company_id)
                ->where('module_key', 'purchases')->where('payload->kind', 'branch_request')
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
            // Doc aliases: itemName->item, priority->urgency (read alias, fall back to original).
            $request->merge([
                'item' => $request->input('item', $request->input('itemName')),
                'urgency' => $request->input('urgency', $request->input('priority')),
            ]);
            $data = $request->validate([
                'item' => 'required|string|max:200', 'qty' => 'required|numeric|min:0', 'unit' => 'required|string|max:16',
                'urgency' => 'sometimes|nullable|in:normal,urgent', 'notes' => 'sometimes|nullable|string',
            ]);
            $op = $this->factory->createFromUpload('purchases', [
                'item' => $data['item'], 'qty' => $data['qty'], 'unit' => $data['unit'],
                'urgency' => $data['urgency'] ?? 'normal', 'notes' => $data['notes'] ?? null, 'kind' => 'branch_request',
            ], $request->user(), $this->branchId($request));
            $this->notifications->pushToRole($request->user()->company_id, 'procurement', 'purchase.request', 'طلب شراء جديد من فرع', $data['item']);
            $this->rt->purchaseRequestNew($request->user()->company_id, [
                'operationId' => $op->id, 'publicId' => $op->public_id, 'item' => $data['item'],
                'qty' => $data['qty'], 'unit' => $data['unit'], 'urgency' => $data['urgency'] ?? 'normal',
                'branchId' => $this->branchId($request),
            ]);

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

            // BRM-3.3 — persist the request so procurement can list/approve it and
            // the branch sees «قيد المراجعة» / «معتمد» chips (was fire-and-forget).
            $req = SupplierRequest::create([
                'company_id' => $request->user()->company_id,
                'branch_id' => $this->branchId($request),
                'name' => $data['name'],
                'category' => $data['category'] ?? null,
                'contact_phone' => $data['contactPhone'] ?? null,
                'reason' => $data['reason'] ?? null,
                'status' => SupplierRequest::STATUS_PENDING,
                'requested_by_id' => $request->user()->id,
            ]);

            $this->notifications->pushToRole($request->user()->company_id, 'procurement', 'supplier.review_request',
                'طلب اعتماد مورد جديد', $data['name'].' — '.($data['reason'] ?? ''));

            return $this->created([
                'id' => $req->id, 'name' => $req->name, 'category' => $req->category,
                'status' => $req->status, 'statusLabel' => SupplierRequest::STATUS_LABELS[$req->status],
                'requested' => true,
            ]);
        });
    }

    public function activeShift(Request $request, \Modules\Admin\Services\ShiftPresenter $presenter): JsonResponse
    {
        return $this->run(function () use ($request, $presenter) {
            $shift = Shift::where('company_id', $request->user()->company_id)
                ->when($this->branchId($request), fn ($q, $b) => $q->where('branch_id', $b))
                ->whereIn('status', ['active', 'late'])->orderByDesc('started_at')->first();

            if (! $shift) {
                return $this->ok(null);
            }
            $phone = $shift->cashier_employee_id ? Employee::where('id', $shift->cashier_employee_id)->value('phone') : null;

            return $this->ok($presenter->present($shift, \Modules\Branch\Models\Branch::where('id', $shift->branch_id)->value('name'), $phone));
        });
    }

    /**
     * BRM-5.1 — open a shift. Persists the selected cashier (id + name),
     * derives the sequential shift number/type from the brand config, and
     * defaults the opening float from that config when the body omits it.
     */
    public function openShift(Request $request, \Modules\Admin\Services\ShiftConfigService $configService, \Modules\Admin\Services\ShiftPresenter $presenter): JsonResponse
    {
        return $this->run(function () use ($request, $configService, $presenter) {
            $branchId = $this->branchId($request);
            // Doc aliases: cashierId->cashierEmpNumber, registerOpeningHalalas->openingCashHalalas.
            $request->merge([
                'cashierEmpNumber' => $request->input('cashierEmpNumber', $request->input('cashierId')),
                'openingCashHalalas' => $request->input('openingCashHalalas', $request->input('registerOpeningHalalas')),
            ]);
            $data = $request->validate([
                'cashierEmpNumber' => 'sometimes|nullable|string',
                'openingCashHalalas' => 'sometimes|nullable|integer|min:0',
            ]);

            $exists = Shift::where('company_id', $request->user()->company_id)->where('branch_id', $branchId)->whereIn('status', ['active', 'late'])->exists();
            if ($exists) {
                throw new AsabException('SHIFT_ALREADY_OPEN', 'A shift is already open for this branch', 'يوجد وردية مفتوحة بالفعل لهذا الفرع', 409);
            }

            // Resolve the cashier within the branch (BRM-5.1 «اختيار الكاشير»).
            $cashier = null;
            if (! empty($data['cashierEmpNumber'])) {
                $cashier = Employee::where('company_id', $request->user()->company_id)->where('branch_id', $branchId)
                    ->where(fn ($q) => $q->where('id', $data['cashierEmpNumber'])->orWhere('emp_number', $data['cashierEmpNumber']))->first();
                if (! $cashier) {
                    throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Employee::class);
                }
            }

            $brandId = \Modules\Branch\Models\Branch::where('id', $branchId)->value('asab_brand_id');
            $config = $brandId ? \Modules\Admin\Models\BrandShiftConfig::where('brand_id', $brandId)->first() : null;
            $classified = $configService->classify($config, now());
            $float = $data['openingCashHalalas'] ?? (int) ($config->shifts['openingFloatHalalas'] ?? \Modules\Admin\Support\ShiftEnums::DEFAULT_FLOAT_HALALAS);

            $shift = Shift::create([
                'company_id' => $request->user()->company_id, 'branch_id' => $branchId,
                'supervisor_user_id' => $request->user()->id, 'supervisor_name' => $request->user()->name,
                'cashier_employee_id' => $cashier?->id, 'cashier_name' => $cashier?->name,
                'shift_no' => $classified['shiftNo'], 'shift_type' => $classified['shiftType'],
                'started_at' => now(), 'status' => 'active',
                'opening_float' => $float,
            ]);
            $this->rt->shiftChanged($shift, 'opened');

            return $this->created($presenter->present($shift, null, $cashier?->phone));
        });
    }

    /**
     * NEW (§5.4): create an Employee for the manager's branch from the doc body
     * {name, role, salaryHalalas, shift}. Maps salaryHalalas->monthlySalary and
     * shift->shiftType, auto-generates empNumber, derives branch from the tenant.
     */
    public function storeEmployee(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'role' => 'required|string|max:80',
                'salaryHalalas' => 'required|integer|min:0',
                'shift' => 'sometimes|nullable|string|max:16',
                'nationalId' => 'sometimes|nullable|string|max:32',
                'hireDate' => 'sometimes|nullable|date',
                'email' => 'sometimes|nullable|email|max:255',
                'phone' => 'sometimes|nullable|string|max:32',
            ]);
            $branchId = $this->branchId($request);

            // Retry on the (company_id, emp_number) unique index so concurrent
            // creates never collide on the derived number (T12.11).
            [$emp, $provision] = $this->createEmployeeWithRetry($request, $data, $branchId);

            $payload = [
                'id' => $emp->id, 'empNumber' => $emp->emp_number, 'name' => $emp->name,
                'role' => $emp->role, 'monthlySalary' => $emp->monthly_salary,
                'shiftType' => $emp->shift_type, 'branchId' => $emp->branch_id, 'status' => $emp->status,
            ];
            if ($provision !== null) {
                $payload['cashier'] = $provision;
            }

            return $this->created($payload);
        });
    }

    /**
     * Create the employee (+ cashier provisioning) in a transaction, retrying
     * when the derived emp_number races another concurrent create.
     *
     * @return array{0: Employee, 1: array|null}
     */
    private function createEmployeeWithRetry(Request $request, array $data, ?string $branchId): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($request, $data, $branchId) {
                    $emp = Employee::create([
                        'company_id' => $request->user()->company_id,
                        'branch_id' => $branchId,
                        'emp_number' => $this->nextEmpNumber($request->user()->company_id),
                        'name' => $data['name'],
                        'phone' => $data['phone'] ?? null,
                        'national_id' => $data['nationalId'] ?? null,
                        'role' => $data['role'],
                        'monthly_salary' => $data['salaryHalalas'],
                        'shift_type' => $data['shift'] ?? null,
                        'hire_date' => $data['hireDate'] ?? now(),
                        'status' => 'active',
                    ]);

                    // Cashier-role employees also get a mobile-app login (WS2 bridge).
                    $provision = null;
                    if ($this->cashiers->isCashierRole($data['role'])) {
                        $provision = $this->cashiers->provision(
                            $branchId, $request->user()->company_id,
                            $data['name'], $data['email'] ?? null, $data['phone'] ?? null,
                            $emp->id,
                        );
                        if ($provision['cashierId']) {
                            $emp->forceFill(['legacy_cashier_id' => $provision['cashierId']])->save();
                        }
                    }

                    return [$emp, $provision];
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }
}
