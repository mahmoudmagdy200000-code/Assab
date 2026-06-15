<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
use Modules\Admin\Services\NotificationService;
use Modules\Admin\Services\OperationFactory;
use Modules\Admin\Services\RealtimeBroadcaster;

/**
 * Company-scoped Branch Manager surface — NEW endpoints beyond the shared
 * BranchDashboardController (COMPANY_DASHBOARD_API_SPEC.md §5.4).
 */
class BranchCompanyController extends AsabController
{
    public function __construct(
        private readonly OperationFactory $factory,
        private readonly NotificationService $notifications,
        private readonly RealtimeBroadcaster $rt,
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

    /** Flat doc-body branch of upload(): one typed report + multipart attachments. */
    private function uploadFlat(Request $request, ?string $branchId, string $reportType): JsonResponse
    {
        $allowed = ['sales', 'inventory', 'cash', 'waste', 'purchases', 'expenses'];
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

        $salesHalalas = (int) ($request->input('salesHalalas') ?? 0);
        $expensesHalalas = (int) ($request->input('expensesHalalas') ?? 0);
        $amount = $reportType === 'expenses' ? $expensesHalalas : $salesHalalas;

        $payload = [
            'date' => $request->input('date'),
            'shift' => $request->input('shift'),
            'salesHalalas' => $salesHalalas,
            'expensesHalalas' => $expensesHalalas,
            'expenseNote' => $request->input('expenseNote'),
        ];

        $op = $this->factory->createFromUpload($reportType, $payload, $request->user(), $branchId, $amount);

        // Persist + link uploaded attachments to the created operation.
        $attachments = $this->storeAttachments($request, $op, 'operation');

        return $this->created([
            // Superset: keep the existing `operations` array contract...
            'operations' => [[
                'id' => $op->id, 'publicId' => $op->public_id, 'moduleKey' => $op->module_key, 'status' => $op->status,
            ]],
            // ...and add the flat doc-shaped keys alongside it.
            'uploadId' => $op->public_id,
            'reportType' => $reportType,
            'status' => 'success',
            'createdOperationId' => $op->id,
            'uploadedAt' => optional($op->submitted_at)->toIso8601String() ?? now()->toIso8601String(),
            'attachments' => $attachments,
        ]);
    }

    /**
     * Store uploaded multipart `attachments[]` on the public disk and link each
     * to the given owner via the Attachment model. Returns presented rows and
     * keeps the operation's attachment_count in sync.
     */
    private function storeAttachments(Request $request, Operation $op, string $ownerType): array
    {
        $files = $request->file('attachments', []);
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }
        if (empty($files)) {
            return [];
        }

        $companyId = $request->user()->company_id ?? 'platform';
        $rows = DB::transaction(function () use ($files, $op, $ownerType, $companyId, $request) {
            $created = [];
            foreach ($files as $file) {
                if (! $file) {
                    continue;
                }
                $path = $file->store($companyId.'/'.$ownerType, 'public');
                $attachment = Attachment::create([
                    'owner_type' => $ownerType,
                    'owner_id' => $op->id,
                    'filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'storage_key' => $path,
                    'public_url' => Storage::disk('public')->url($path),
                    'label' => $op->module_key,
                    'uploaded_by_id' => $request->user()->id,
                    'uploaded_at' => now(),
                ]);
                $created[] = $attachment;
            }
            $op->update(['attachment_count' => (int) $op->attachment_count + count($created)]);

            return $created;
        });

        return array_map(fn (Attachment $a) => [
            'id' => $a->id, 'filename' => $a->filename, 'mimeType' => $a->mime_type,
            'size' => $a->size, 'publicUrl' => $a->public_url,
            'uploadedAt' => optional($a->uploaded_at)->toIso8601String(),
        ], $rows);
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
            // Doc aliases: cashierId->cashierEmpNumber, registerOpeningHalalas->openingCashHalalas.
            $request->merge([
                'cashierEmpNumber' => $request->input('cashierEmpNumber', $request->input('cashierId')),
                'openingCashHalalas' => $request->input('openingCashHalalas', $request->input('registerOpeningHalalas')),
            ]);
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
            $this->rt->shiftChanged($shift, 'opened');

            return $this->created($this->present($shift));
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
            ]);
            $branchId = $this->branchId($request);

            $emp = Employee::create([
                'company_id' => $request->user()->company_id,
                'branch_id' => $branchId,
                'emp_number' => $this->nextEmpNumber($request->user()->company_id, $branchId),
                'name' => $data['name'],
                'national_id' => $data['nationalId'] ?? null,
                'role' => $data['role'],
                'monthly_salary' => $data['salaryHalalas'],
                'shift_type' => $data['shift'] ?? null,
                'hire_date' => $data['hireDate'] ?? now(),
                'status' => 'active',
            ]);

            return $this->created([
                'id' => $emp->id, 'empNumber' => $emp->emp_number, 'name' => $emp->name,
                'role' => $emp->role, 'monthlySalary' => $emp->monthly_salary,
                'shiftType' => $emp->shift_type, 'branchId' => $emp->branch_id, 'status' => $emp->status,
            ]);
        });
    }

    /** Auto-generate a unique employee number scoped to the company. */
    private function nextEmpNumber(?string $companyId, ?string $branchId): string
    {
        $n = Employee::withTrashed()
            ->when($companyId, fn ($q, $c) => $q->where('company_id', $c))
            ->count() + 1;

        return 'EMP-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * NEW (§5.4): persist a per-brand shift configuration from the doc body
     * {brandId, numShifts, durationHours, firstStart, shifts:[{start,end}],
     * restaurantOverrides:{}}. Stored on BrandShiftConfig keyed by brand.
     */
    public function saveShiftConfig(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'brandId' => 'required|string',
                'numShifts' => 'required|integer|min:1|max:24',
                'durationHours' => 'required|integer|min:1|max:24',
                'firstStart' => 'required|string|max:16',
                'shifts' => 'sometimes|array',
                'shifts.*.start' => 'required_with:shifts|string|max:16',
                'shifts.*.end' => 'required_with:shifts|string|max:16',
                'restaurantOverrides' => 'sometimes|array',
            ]);

            $cfg = DB::transaction(function () use ($data) {
                $cfg = BrandShiftConfig::firstOrNew(['brand_id' => $data['brandId']]);
                $cfg->num_shifts = $data['numShifts'];
                $cfg->duration_hours = $data['durationHours'];
                $cfg->first_shift_start = $data['firstStart'];
                $cfg->shifts = [
                    'firstStart' => $data['firstStart'],
                    'shifts' => $data['shifts'] ?? [],
                    'restaurantOverrides' => $data['restaurantOverrides'] ?? [],
                ];
                $cfg->save();

                return $cfg;
            });

            return $this->ok([
                'id' => $cfg->id,
                'brandId' => $cfg->brand_id,
                'numShifts' => $cfg->num_shifts,
                'durationHours' => $cfg->duration_hours,
                'firstStart' => $cfg->first_shift_start,
                'shifts' => $cfg->shifts['shifts'] ?? [],
                'restaurantOverrides' => $cfg->shifts['restaurantOverrides'] ?? [],
            ]);
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
