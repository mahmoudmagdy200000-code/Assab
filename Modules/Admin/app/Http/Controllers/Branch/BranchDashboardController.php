<?php

namespace Modules\Admin\Http\Controllers\Branch;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\SupplierRequest;
use Modules\Admin\Services\BranchEmployeeDirectoryService;
use Modules\Admin\Services\BranchOverviewService;
use Modules\Admin\Services\ExpenseInvoiceService;
use Modules\Admin\Services\OperationAttachmentService;
use Modules\Admin\Services\OperationFactory;
use Modules\Admin\Support\CashierRole;

/**
 * Branch Manager (مدير الفرع) web companion (BACKEND_API_SPEC.md §6.4).
 * Upload endpoints create pending Operations that enter the approval pipeline.
 */
class BranchDashboardController extends AsabController
{
    public function __construct(
        private readonly OperationFactory $factory,
        private readonly BranchEmployeeDirectoryService $directory,
        private readonly ExpenseInvoiceService $invoices,
        private readonly BranchOverviewService $overviewService,
        private readonly OperationAttachmentService $attachments,
        private readonly \Modules\Admin\Services\BranchDailyReportsService $dailyReports,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok(
            $this->overviewService->build($this->branchId($request), $request->user()->company_id),
        ));
    }

    public function uploadStatus(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $branchId = $this->branchId($request);

            return $this->ok([
                'date' => today()->toDateString(),
                'reports' => $this->dailyReports->reports($branchId),
            ]);
        });
    }

    public function upload(Request $request, string $reportType): JsonResponse
    {
        return $this->run(function () use ($request, $reportType) {
            $moduleMap = [
                'sales' => 'sales', 'inventory' => 'inventory', 'cash' => 'cash',
                'waste' => 'waste', 'purchases' => 'purchases', 'expenses' => 'expenses',
                // The evening-close report (BRM checklist) rides the shifts module.
                'shift-close' => 'shifts',
            ];
            if (! isset($moduleMap[$reportType])) {
                return $this->fail('INVALID_INPUT', 'Unknown report type', 'نوع تقرير غير معروف', [], 400);
            }

            // BRM-2.1 — validate the body (no raw $request->all()), accept
            // multipart attachments, and stamp the dashboard channel.
            $rules = [
                'date' => 'sometimes|nullable|date',
                'shift' => 'sometimes|nullable|in:صباحي,مسائي,كامل اليوم,morning,evening,full_day',
                'totalSales' => 'sometimes|nullable|integer|min:0',
                'amount' => 'sometimes|nullable|integer|min:0',
                'note' => 'sometimes|nullable|string',
                'attachments' => 'sometimes|array',
                'attachments.*' => 'file|max:10240',
            ];
            // ACC-2.2 — an expenses statement is a list of invoices, and its total
            // is the sum of them, never a number the client picks.
            if ($reportType === 'expenses') {
                $rules = array_merge($rules, $this->invoices->uploadRules());
            }
            // Meeting 2026-07-30: the item lines were validated away, so every
            // waste/inventory op stored an empty payload and the الهدر report
            // aggregated nothing. Accept and persist them.
            if ($reportType === 'inventory') {
                $rules = array_merge($rules, [
                    'items' => 'sometimes|array',
                    'items.*.itemId' => 'required_with:items|string',
                    'items.*.name' => 'sometimes|nullable|string|max:200',
                    'items.*.unit' => 'sometimes|nullable|string|max:32',
                    'items.*.actualQty' => 'required_with:items|numeric|min:0',
                    'items.*.expectedQty' => 'sometimes|nullable|numeric',
                    'items.*.openingQty' => 'sometimes|nullable|numeric',
                    'items.*.unitPriceHalalas' => 'sometimes|nullable|integer|min:0',
                ]);
            }
            if ($reportType === 'waste') {
                $rules = array_merge($rules, [
                    'products' => 'sometimes|array',
                    'products.*.itemId' => 'sometimes|nullable|string',
                    'products.*.name' => 'required_with:products|string|max:200',
                    'products.*.qty' => 'required_with:products|numeric|min:0',
                    'products.*.value' => 'required_with:products|integer|min:0',
                    'products.*.classification' => 'sometimes|nullable|string|max:40',
                    'products.*.responsibility' => 'sometimes|nullable|string|max:40',
                ]);
            }
            $data = $request->validate($rules);

            $amount = match (true) {
                $reportType === 'expenses' => $this->invoices->statementTotal($request->input('invoices', [])),
                // A waste report with product lines totals from them, never
                // from a client-picked number.
                $reportType === 'waste' && ! empty($data['products']) => (int) array_sum(array_column($data['products'], 'value')),
                default => (int) ($data['totalSales'] ?? $data['amount'] ?? 0),
            };

            // Only validated keys enter the payload.
            $payload = array_filter([
                'date' => $data['date'] ?? null, 'shift' => $data['shift'] ?? null,
                'totalSales' => $data['totalSales'] ?? null, 'amount' => $data['amount'] ?? null,
                'note' => $data['note'] ?? null,
                'invoices' => $reportType === 'expenses' ? ($data['invoices'] ?? null) : null,
                'items' => $reportType === 'inventory' ? ($data['items'] ?? null) : null,
                'products' => $reportType === 'waste' ? ($data['products'] ?? null) : null,
            ], fn ($v) => $v !== null);

            $op = $this->factory->createFromUpload(
                $moduleMap[$reportType], $payload, $request->user(), $this->branchId($request), $amount, 'mobile', 'dashboard',
            );
            $attachments = $this->attachments->store($request, $op, 'operation');

            return $this->created([
                'id' => $op->id,
                'publicId' => $op->public_id,
                'moduleKey' => $op->module_key,
                'status' => $op->status,
                'origin' => $op->origin,
                'channel' => $op->channel,
                'attachments' => $attachments,
            ]);
        });
    }

    public function employees(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            // Dashboard employees + the cashiers this branch's manager added
            // from the mobile app (cashier accounts are mobile-only).
            $page = $this->directory->paginate($this->branchId($request), [
                'search' => $request->query('search'),
                'status' => $request->query('status'),
                'page' => (int) $request->query('page', 1),
                'pageSize' => (int) $request->query('pageSize', 25),
            ]);

            return $this->paginated($page);
        });
    }

    public function storeEmployee(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'empNumber' => 'required|string|max:32',
                'name' => 'required|string|max:200',
                'nationalId' => 'nullable|string|max:32',
                'role' => 'required|string|max:80',
                'monthlySalary' => 'required|integer|min:0',
                'shiftType' => 'nullable|string|max:16',
                'hireDate' => 'nullable|date',
                'phone' => 'nullable|string|max:32',
            ]);
            // Cashier accounts live in the mobile app only (branch manager adds them).
            CashierRole::assertNotCashier($data['role']);
            $branchId = $this->branchId($request);

            $emp = Employee::create([
                'branch_id' => $branchId,
                'emp_number' => $data['empNumber'],
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'national_id' => $data['nationalId'] ?? null,
                'role' => $data['role'],
                'monthly_salary' => $data['monthlySalary'],
                'shift_type' => $data['shiftType'] ?? null,
                'hire_date' => $data['hireDate'] ?? now(),
                'status' => 'active',
            ]);

            return $this->created(['id' => $emp->id, 'empNumber' => $emp->emp_number, 'name' => $emp->name]);
        });
    }

    public function items(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $branchId = $this->branchId($request);
            $present = fn ($i) => [
                'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'unit' => $i->unit,
                'cat' => $i->category, 'category' => $i->category,
                'priceHalalas' => (int) $i->unit_price,
                'minLevel' => $i->min_level !== null ? (float) $i->min_level : null,
                'expectedQty' => $i->expected_qty !== null ? (float) $i->expected_qty : null,
            ] + $this->stockStatus($i);

            // Items the admin/accountant assigned to this branch's daily list.
            $listRows = \Modules\Admin\Models\BranchInventoryList::where('branch_id', $branchId)->limit(500)->get();
            if ($listRows->isNotEmpty()) {
                $items = \Modules\Admin\Models\InventoryCatalogItem::whereIn('id', $listRows->pluck('catalog_item_id'))
                    ->orderBy('category')->orderBy('name')->get();
                $configuredBy = \Modules\Admin\Models\AsabUser::whereKey($listRows->pluck('added_by_id')->filter()->first())
                    ->value('name');

                return $this->ok(['items' => $items->map($present)->all(), 'configuredBy' => $configuredBy]);
            }

            // No branch list configured yet — fall back to the brand-wide sales catalog.
            $brandId = \Modules\Branch\Models\Branch::whereKey($branchId)
                ->where('asab_company_id', $request->user()->company_id)
                ->value('asab_brand_id');
            $items = $brandId
                ? \Modules\Admin\Models\InventoryCatalogItem::where('brand_id', $brandId)
                    ->where('type', \Modules\Admin\Models\InventoryCatalogItem::TYPE_SALES_ITEM)
                    ->orderBy('category')->orderBy('name')->limit(500)->get()->map($present)->all()
                : [];

            return $this->ok(['items' => $items, 'configuredBy' => null]);
        });
    }

    public function suppliers(Request $request): JsonResponse
    {
        return $this->run(function () {
            // Company dashboard suppliers (asab_suppliers, tenant-scoped via
            // BelongsToTenant) — same store/keys as the procurement surface.
            $items = \Modules\Admin\Models\AsabSupplier::where('status', 'active')
                ->orderBy('name')->limit(200)->get();

            $rows = $items->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'category' => $s->category,
                'contactName' => $s->contact_name,
                'contactPhone' => $s->contact_phone,
                'contactEmail' => $s->contact_email,
                'commercialReg' => $s->commercial_reg,
                'address' => $s->address ?? null,
                'paymentTerms' => $s->payment_terms,
                'rating' => (int) ($s->rating ?? 0),
                'status' => $s->status,
                'statusLabel' => SupplierRequest::STATUS_LABELS[SupplierRequest::STATUS_APPROVED],
                'isActive' => true,
                'isRequest' => false,
            ])->all();

            // BRM-3.3 — the branch's still-pending «طلب مورد جديد» rows, so the
            // «قيد المراجعة» chip has a data source.
            $pending = SupplierRequest::where('status', SupplierRequest::STATUS_PENDING)
                ->orderByDesc('created_at')->limit(100)->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'category' => $r->category,
                    'contactName' => null,
                    'contactPhone' => $r->contact_phone,
                    'contactEmail' => null,
                    'commercialReg' => null,
                    'address' => null,
                    'paymentTerms' => null,
                    'rating' => 0,
                    'status' => $r->status,
                    'statusLabel' => SupplierRequest::STATUS_LABELS[$r->status],
                    'isActive' => false,
                    'isRequest' => true,
                ])->all();

            return $this->listResponse(array_merge($rows, $pending));
        });
    }

    public function settings(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->settingsPayload($request)));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            // Shift timings (openTime/closeTime/shiftDuration) and branch
            // identity (branchName/phone/address) are intentionally NOT
            // accepted here: they are set by the admin and read-only for the
            // branch-manager role (client requirement §6.4). Extra keys the FE
            // still sends are silently ignored.
            $data = $request->validate([
                'manager' => 'sometimes|nullable|string|max:200',
                'taxNumber' => 'sometimes|nullable|string|max:64',
                'bankAccount' => 'sometimes|nullable|string|max:64',
                'cashLimitHalalas' => 'sometimes|nullable|integer|min:0',
                'wasteThreshold' => 'sometimes|nullable|numeric|min:0',
                'autoReminders' => 'sometimes|boolean',
                'requireImages' => 'sometimes|boolean',
            ]);

            $existing = \Modules\Admin\Models\Setting::where('company_id', $request->user()->company_id)
                ->where('group_key', 'branch:'.$this->branchId($request))->first()->payload ?? [];

            // Typed partial merge over the stored payload (no $request->all());
            // omitted keys — including admin-set shift timings — keep their value.
            $updates = [];
            foreach (['manager', 'taxNumber', 'bankAccount', 'wasteThreshold'] as $key) {
                if (array_key_exists($key, $data)) {
                    $updates[$key] = $data[$key];
                }
            }
            if (array_key_exists('cashLimitHalalas', $data)) {
                $updates['cashLimitHalalas'] = $data['cashLimitHalalas'] === null ? null : (int) $data['cashLimitHalalas'];
            }
            foreach (['autoReminders', 'requireImages'] as $key) {
                if (array_key_exists($key, $data)) {
                    $updates[$key] = (bool) $data[$key];
                }
            }

            $payload = array_merge($existing, $updates);
            // Admin-owned branch identity never lives in this payload.
            unset($payload['branchName'], $payload['phone'], $payload['address'], $payload['readOnlyFields']);

            \Modules\Admin\Models\Setting::updateOrCreate(
                ['company_id' => $request->user()->company_id, 'group_key' => 'branch:'.$this->branchId($request)],
                ['payload' => $payload],
            );

            // Serve the same fully-populated shape as the GET so the FE can
            // rehydrate its form from the response without a second round-trip.
            return $this->ok($this->settingsPayload($request));
        });
    }

    /**
     * Fully-populated branch-settings shape (FE branch-manager wizard §4.2).
     * Locked identity + admin-set shift config come from the Branch/brand
     * records; editable prefs come from the stored Setting payload, each with a
     * sensible default so the FE always receives every field on every GET.
     */
    private function settingsPayload(Request $request): array
    {
        $branchId = $this->branchId($request);
        $companyId = $request->user()->company_id;

        $stored = \Modules\Admin\Models\Setting::where('company_id', $companyId)
            ->where('group_key', 'branch:'.$branchId)->first()->payload ?? [];

        // Branch identity + working hours: admin-maintained, read-only here.
        $branch = \Modules\Branch\Models\Branch::whereKey($branchId)
            ->where('asab_company_id', $companyId)->first();

        // Admin-set shift configuration (read-only mirror for this role).
        $brandId = $branch?->asab_brand_id;
        $cfg = $brandId ? \Modules\Admin\Models\BrandShiftConfig::where('brand_id', $brandId)->first() : null;

        return [
            // Locked identity (admin-owned) — mirrored, never edited here.
            'branchName' => $branch?->name,
            'phone' => $branch?->phone,
            'address' => $branch?->address,
            // Editable prefs (stored value → sensible default).
            'manager' => $stored['manager'] ?? $branch?->manager,
            'taxNumber' => $stored['taxNumber'] ?? null,
            'bankAccount' => $stored['bankAccount'] ?? null,
            'cashLimitHalalas' => isset($stored['cashLimitHalalas']) ? (int) $stored['cashLimitHalalas'] : null,
            'wasteThreshold' => $stored['wasteThreshold'] ?? null,
            'autoReminders' => (bool) ($stored['autoReminders'] ?? true),
            'requireImages' => (bool) ($stored['requireImages'] ?? true),
            // Admin/shift-derived, read-only.
            'workingHours' => [
                'open' => $stored['workingHours']['open'] ?? $stored['openTime'] ?? $branch?->opening_hours?->format('H:i') ?? '08:00',
                'close' => $stored['workingHours']['close'] ?? $stored['closeTime'] ?? $branch?->closing_hours?->format('H:i') ?? '23:00',
            ],
            'shiftDurationHours' => $cfg?->duration_hours ?? ($stored['shiftDurationHours'] ?? null),
            'readOnlyFields' => ['branchName', 'phone', 'address'],
            'shiftConfig' => $cfg ? [
                'numShifts' => $cfg->num_shifts,
                'durationHours' => $cfg->duration_hours,
                'firstStart' => $cfg->first_shift_start,
                'shifts' => $cfg->shifts,
                'readOnly' => true,
            ] : ['readOnly' => true],
        ];
    }

    public function confirmAsset(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $asset = \Modules\Admin\Models\Asset::where('branch_id', $this->branchId($request))->findOrFail($id);
            $asset->update(['status' => 'pending_accountant']);

            return $this->ok(['id' => $asset->id, 'status' => $asset->status]);
        });
    }

    public function reconfirmInventory(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $op = \Modules\Admin\Models\Operation::where('branch_id', $this->branchId($request))
                ->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->firstOrFail();
            $payload = $op->payload ?? [];
            $payload['branchReconfirmedAt'] = now()->toIso8601String();
            $op->update(['payload' => $payload]);

            return $this->ok(['id' => $op->id, 'reconfirmed' => true]);
        });
    }

    /**
     * BRM-4.1 stock status from the expected on-hand qty vs the reorder level:
     * ≤ min = حرج, ≤ 1.5× min = منخفض, else كافٍ. Defaults to كافٍ when no
     * threshold/expected qty is configured.
     *
     * @return array{stockStatus:string, stockStatusLabel:string}
     */
    private function stockStatus($item): array
    {
        $min = $item->min_level !== null ? (float) $item->min_level : null;
        $expected = $item->expected_qty !== null ? (float) $item->expected_qty : null;

        $key = 'ok';
        if ($min !== null && $expected !== null) {
            $key = match (true) {
                $expected <= $min => 'critical',
                $expected <= $min * 1.5 => 'low',
                default => 'ok',
            };
        }

        return ['stockStatus' => $key, 'stockStatusLabel' => ['ok' => 'كافٍ', 'low' => 'منخفض', 'critical' => 'حرج'][$key]];
    }

    /**
     * The branch this manager acts on. A branchId query param is honored only
     * when it belongs to the caller's role assignment — never trusted raw.
     */
    private function branchId(Request $request): ?string
    {
        $ctx = app(\Modules\Admin\Support\TenantContext::class);

        $requested = $request->query('branchId');
        if ($requested !== null && in_array($requested, $ctx->branchIds, true)) {
            return $requested;
        }

        return $ctx->branchIds[0] ?? null;
    }
}
