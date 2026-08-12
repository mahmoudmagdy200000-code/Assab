<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Http\Controllers\Concerns\MapsAssetSpreadsheet;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\SettlementRequest;
use Modules\Admin\Services\AccountantDashboardService;
use Modules\Admin\Services\AssetSequence;
use Modules\Admin\Services\BrandBranchResolver;
use Modules\Admin\Services\CustodyService;
use Modules\Admin\Services\ExpenseInvoiceService;
use Modules\Admin\Services\ExpenseKpiService;
use Modules\Admin\Services\SalesCompletenessService;
use Modules\Admin\Services\SalesKpiService;
use Modules\Admin\Services\SalesVarianceService;
use Modules\Admin\Support\AssetEnums;
use Modules\Admin\Support\ModuleCatalog;
use Modules\Admin\Support\TenantContext;

/**
 * Company-scoped Accountant surface — the endpoints with NEW logic beyond what
 * the shared Accountant controllers already provide (COMPANY_DASHBOARD_API_SPEC.md §5.3).
 */
class AccountantCompanyController extends AsabController
{
    use MapsAssetSpreadsheet;

    public function __construct(
        private readonly SalesVarianceService $salesVariance,
        private readonly AccountantDashboardService $dashboards,
        private readonly SalesKpiService $salesKpis,
        private readonly SalesCompletenessService $completeness,
        private readonly ExpenseInvoiceService $invoices,
        private readonly ExpenseKpiService $expenseKpi,
        private readonly TenantContext $tenant,
        private readonly CustodyService $custody,
        private readonly BrandBranchResolver $brandBranches,
    ) {}

    /** GET …/sales/kpis — ACC-1.1 cards + ACC-1.5 variance banner. */
    public function salesKpis(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate(['date' => 'sometimes|date']);

            return $this->ok($this->salesKpis->forDate(
                $this->tenantCompanyIdsFor($request->user()),
                $this->assignedBranchIds(),
                $request->query('date'),
            ));
        });
    }

    /** GET …/sales/day-completeness — ACC-1.2 day pills + «n مطلوبة — m مكتملة · k ناقصة». */
    public function salesDayCompleteness(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate(['days' => 'sometimes|integer|min:1|max:31']);

            return $this->listResponse($this->completeness->days(
                $this->tenantCompanyIdsFor($request->user()),
                $this->assignedBranchIds(),
                (int) $request->query('days', 7),
            ));
        });
    }

    /** GET /company/me/accountant/dashboard — ACC-0 «ملخص اليوم». */
    public function dashboard(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyIds = $this->tenantCompanyIdsFor($request->user());
            $branchIds = $this->assignedBranchIds();
            $actor = $request->user();
            $base = fn () => $this->scopeToAssignedBranches(Operation::whereIn('company_id', $companyIds));

            $kpis = $this->dashboards->kpis($actor, $branchIds);
            $modules = $this->dashboards->moduleGrid($actor, $branchIds);

            return $this->ok([
                'today' => now()->toDateString(),
                'counts' => $kpis,
                'scope' => $this->dashboards->scope($actor, $branchIds, $this->tenant),
                'modules' => $modules,
                'progressToday' => $this->dashboards->progressToday($actor, $branchIds),
                // Sparse pending-only map kept for the pre-T04 company dashboard.
                'pendingByModule' => collect($modules)->filter(fn ($m) => $m['pendingCount'] > 0)
                    ->pluck('pendingCount', 'key'),
                'needsAttention' => $this->needsAttention($base()),
                'rejectedReuploadNeededCount' => $kpis['rejected'],
            ]);
        });
    }

    /** @return array<int, array<string, mixed>> spec §5.3.1 needsAttention shape */
    private function needsAttention($base): array
    {
        $ops = (clone $base)->where('match', 'diff')->whereIn('status', ['pending', 'approved'])->limit(10)->get();
        $branchNames = \Modules\Branch\Models\Branch::whereIn('id', $ops->pluck('branch_id')->filter()->unique())->pluck('name', 'id');

        return $ops->map(fn (Operation $o) => [
            'operationId' => $o->id, 'refNum' => $o->public_id, 'branch' => $branchNames[$o->branch_id] ?? '—',
            'moduleLabel' => ModuleCatalog::labelAr($o->module_key), 'match' => $o->match, 'diff' => $o->diff_note,
        ])->all();
    }

    public function salesVarianceAssign(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate([
                'allocations' => 'required|array|min:1',
                'allocations.*.employeeId' => 'sometimes|string',
                'allocations.*.empNumber' => 'sometimes|string',
                'allocations.*.amountHalalas' => 'required|integer|min:1',
                'notes' => 'sometimes|nullable|string|max:1000',
            ]);
            $op = $this->scopeToAssignedBranches(Operation::whereIn('company_id', $this->tenantCompanyIdsFor($request->user())))
                ->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->where('module_key', 'sales')->firstOrFail();

            $result = $this->salesVariance->assign($op, $data['allocations'], $data['notes'] ?? null, $request->user());
            // Doc superset: surface remainingVarianceHalalas if the service output lacks it.
            if (! array_key_exists('remainingVarianceHalalas', $result)) {
                $result['remainingVarianceHalalas'] = $result['remainingUnallocatedHalalas'] ?? 0;
            }

            return $this->ok($result);
        });
    }

    public function employeeLookup(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $num = $request->query('empNumber');
            $emp = $this->scopeToAssignedBranches(Employee::whereIn('company_id', $this->tenantCompanyIdsFor($request->user())))
                ->where('branch_id', $branchId)->where('emp_number', $num)->first();
            if (! $emp) {
                throw new AsabException('NOT_FOUND', 'Employee not found', 'الموظف غير موجود', 404);
            }

            return $this->ok(['empNumber' => $emp->emp_number, 'name' => $emp->name]);
        });
    }

    /** GET …/expenses/kpis — ACC-2.1 cards + the invoice-match split. */
    public function expenseKpis(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate(['dateFrom' => 'sometimes|date', 'dateTo' => 'sometimes|date|after_or_equal:dateFrom']);

            return $this->ok($this->expenseKpi->forRange(
                $this->tenantCompanyIdsFor($request->user()),
                $this->assignedBranchIds(),
                $request->query('dateFrom'),
                $request->query('dateTo'),
            ));
        });
    }

    /**
     * POST …/expense-invoices/{invoiceId}/verify — ACC-2.2 توثيق.
     *
     * `{invoiceId}` is the expenses **operation**; `invoiceIndex` selects the row
     * inside its statement (default 0, the single-invoice case).
     */
    public function verifyExpense(Request $request, string $invoiceId): JsonResponse
    {
        return $this->run(function () use ($request, $invoiceId) {
            $index = $this->invoiceIndex($request);

            return $this->ok($this->invoices->verify($this->expenseOp($request, $invoiceId), $index, $request->user()));
        });
    }

    public function unverifyExpense(Request $request, string $invoiceId): JsonResponse
    {
        return $this->run(function () use ($request, $invoiceId) {
            $index = $this->invoiceIndex($request);

            return $this->ok($this->invoices->unverify($this->expenseOp($request, $invoiceId), $index));
        });
    }

    /**
     * PATCH …/expense-invoices/{invoiceId}/invoices/{invoiceIndex} — ACC-2.3.
     * Records what the accountant read off the attached document; the match badge
     * and the «⚠ فرق: … ر.س» delta are re-derived from it.
     */
    public function reviewInvoice(Request $request, string $invoiceId, string $invoiceIndex): JsonResponse
    {
        return $this->run(function () use ($request, $invoiceId, $invoiceIndex) {
            $data = $request->validate([
                'documentAmountHalalas' => 'sometimes|nullable|integer|min:0',
                'documentVendor' => 'sometimes|nullable|string|max:200',
                'documentInvNum' => 'sometimes|nullable|string|max:64',
                'documentDate' => 'sometimes|nullable|date',
            ]);

            return $this->ok($this->invoices->setDocument(
                $this->expenseOp($request, $invoiceId), (int) $invoiceIndex, $data,
            ));
        });
    }

    /**
     * GET …/expense-invoices/{invoiceId}/attachments — the three ACC-2 documents
     * (صورة الفاتورة / ختم وتوقيع / الإجماليات), grouped per invoice.
     * `?invoiceIndex=` narrows to one invoice.
     */
    public function expenseAttachments(Request $request, string $invoiceId): JsonResponse
    {
        return $this->run(function () use ($request, $invoiceId) {
            $op = $this->expenseOp($request, $invoiceId);
            $byIndex = $this->invoices->attachmentRows($op);

            if ($request->query('invoiceIndex') !== null) {
                $index = $this->invoiceIndex($request);
                $this->invoices->invoiceAt($op, $index);

                return $this->listResponse($byIndex[$index] ?? [], ['invoiceIndex' => $index]);
            }

            $groups = [];
            foreach ($byIndex as $index => $rows) {
                $groups[] = ['invoiceIndex' => $index === -1 ? null : $index, 'attachments' => $rows];
            }

            return $this->listResponse($groups, ['total' => array_sum(array_map('count', $byIndex))]);
        });
    }

    private function invoiceIndex(Request $request): int
    {
        $request->validate(['invoiceIndex' => 'sometimes|integer|min:0']);

        return (int) ($request->input('invoiceIndex') ?? $request->query('invoiceIndex') ?? 0);
    }

    public function inventorySendNotification(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, \Modules\Admin\Services\NotificationService $notifications, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $notifications, $branchId) {
            $data = $request->validate(['itemIndices' => 'sometimes|array', 'note' => 'sometimes|string']);
            $op = $this->latestInventoryOp($request, $branchId);
            if ($op) {
                $payload = $op->payload ?? [];
                $payload['notifSentAt'] = now()->toIso8601String();
                $op->update(['payload' => $payload]);
            }
            $rt->inventoryFlagSent($branchId, $data['itemIndices'] ?? []);
            // T07.9 — durable notification so an offline branch manager still learns.
            $notifications->pushToBranch(
                $request->user()->company_id, $branchId, 'branch', 'inventory.flagged',
                'أصناف بحاجة إلى مراجعة الجرد', $data['note'] ?? 'راجع الأصناف المُعلَّمة وأكِّد الجرد',
                null, $op ? ['type' => 'operation', 'id' => $op->id] : [],
            );

            return $this->ok(['notifSentAt' => now()->toIso8601String(), 'notifiedBranch' => true]);
        });
    }

    public function inventoryMarkConfirmed(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $data = $request->validate(['confirmed' => 'sometimes|boolean']);
            $confirmed = $data['confirmed'] ?? true;
            $op = $this->latestInventoryOp($request, $branchId);
            if (! $op) {
                throw new AsabException('NOT_FOUND', 'No inventory submission for that branch', 'لا يوجد جرد لهذا الفرع', 404);
            }
            $payload = $op->payload ?? [];
            $payload['isConfirmed'] = $confirmed;
            // T07.9 — index derives «أكّده الفرع» from branchReconfirmedAt; stamp it
            // so the accountant-recorded confirmation actually shows in the list.
            $payload['branchReconfirmedAt'] = $confirmed ? now()->toIso8601String() : null;
            $op->update(['payload' => $payload]);

            return $this->ok(['branchId' => $branchId, 'isConfirmed' => $confirmed]);
        });
    }

    /**
     * PATCH …/assets/{id} — the register's edit drawer (SRS §4.2).
     *
     * Only keys the caller actually sent are written, so `custodian: null`
     * clears the custodian instead of being silently dropped.
     */
    public function updateAsset(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $asset = $this->scopeToAssignedBranches(Asset::whereIn('company_id', $this->tenantCompanyIdsFor($request->user())))->findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200', 'category' => 'sometimes|string|max:32', 'custodian' => 'sometimes|nullable|string|max:200',
                'status' => ['sometimes', 'string', AssetEnums::statusRule()],
                'serial' => 'sometimes|nullable|string|max:64', 'purchaseDate' => 'sometimes|nullable|date',
                // 'bookValue' is canonical; 'bookValueHalalas' is the doc alias — accept either.
                'bookValue' => 'sometimes|integer|min:0', 'bookValueHalalas' => 'sometimes|integer|min:0',
                'branchId' => 'sometimes|nullable|string', 'note' => 'sometimes|nullable|string',
            ]);
            if (($data['branchId'] ?? null) !== null) {
                $this->assertBranchAssigned($data['branchId']);
            }

            $columns = [
                'name' => 'name', 'category' => 'category', 'custodian' => 'custodian', 'status' => 'status',
                'serial' => 'serial', 'purchaseDate' => 'purchased_at', 'branchId' => 'branch_id', 'note' => 'notes',
            ];
            $patch = [];
            foreach ($columns as $field => $column) {
                if (array_key_exists($field, $data)) {
                    $patch[$column] = $data[$field];
                }
            }
            if (array_key_exists('bookValue', $data) || array_key_exists('bookValueHalalas', $data)) {
                $patch['book_value'] = $data['bookValue'] ?? $data['bookValueHalalas'];
            }
            $asset->update($patch);

            return $this->ok([
                'id' => $asset->id,
                'name' => $asset->name,
                'status' => $asset->status,
                'statusLabelAr' => AssetEnums::statusLabelAr($asset->status),
                'category' => $asset->category,
                'categoryLabelAr' => AssetEnums::categoryLabelAr($asset->category),
                'branchId' => $asset->branch_id,
                'custodian' => $asset->custodian,
                'serial' => $asset->serial,
                'purchaseDate' => optional($asset->purchased_at)->toIso8601String(),
                'bookValue' => $asset->book_value,
                'bookValueHalalas' => $asset->book_value,
                'note' => $asset->notes,
                'notes' => $asset->notes,
            ]);
        });
    }

    /**
     * POST /assets/import — parse an uploaded Excel/CSV and create assets.
     * Header-driven mapping (Arabic + English), monetary values read as SAR →
     * halalas. Returns the real parsed-row count (spec §5.3.7, Response 202).
     */
    public function importAssets(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt']);
            $companyId = $request->user()->company_id;
            $userId = $request->user()->id;
            $path = $request->file('file')->getRealPath();
            $ext = strtolower($request->file('file')->getClientOriginalExtension());

            $reader = $ext === 'csv' || $ext === 'txt'
                ? new \OpenSpout\Reader\CSV\Reader
                : new \OpenSpout\Reader\XLSX\Reader;
            $reader->open($path);

            $map = [];          // column index → field
            $parsed = 0;
            $created = 0;
            $imported = [];     // created asset summaries (doc superset)
            $errors = [];       // per-row import errors (doc superset)

            $allowedBranchIds = app(\Modules\Admin\Services\TenantBranchResolver::class)
                ->legacyBranchIds(app(\Modules\Admin\Support\TenantContext::class));

            DB::transaction(function () use ($reader, &$map, &$parsed, &$created, &$imported, &$errors, $companyId, $userId, $allowedBranchIds) {
                // Same allocator as the single-asset create, so an import and a
                // manual registration never mint the same FA-xxxx.
                $seq = (int) substr(AssetSequence::reserve($companyId)[0], strlen(AssetSequence::PREFIX) + 1);
                foreach ($reader->getSheetIterator() as $sheet) {
                    $isHeader = true;
                    foreach ($sheet->getRowIterator() as $row) {
                        $cells = $row->toArray();
                        if ($isHeader) {
                            $map = $this->mapAssetHeaders($cells);
                            $isHeader = false;

                            continue;
                        }
                        $name = $this->cell($cells, $map, 'name');
                        if ($name === null || trim((string) $name) === '') {
                            continue; // skip blank rows
                        }
                        $parsed++;
                        // Spreadsheet branch ids are caller data: only branches
                        // inside the accountant's assigned scope may be stamped.
                        $rowBranchId = $this->cell($cells, $map, 'branchId') ?: null;
                        if ($rowBranchId !== null && $allowedBranchIds !== null
                            && ! in_array((string) $rowBranchId, $allowedBranchIds, true)) {
                            $errors[] = ['row' => $parsed + 1, 'message' => 'branch outside assigned scope'];

                            continue;
                        }
                        $usefulLife = (int) ($this->cell($cells, $map, 'usefulLife') ?? 0) ?: null;
                        if ($usefulLife !== null && ! in_array($usefulLife, AssetEnums::USEFUL_LIFE_MONTHS, true)) {
                            $errors[] = ['row' => $parsed + 1, 'message' => 'useful life must be one of '.implode('/', AssetEnums::USEFUL_LIFE_MONTHS)];

                            continue;
                        }
                        $cost = $this->toHalalas($this->cell($cells, $map, 'cost'));
                        $asset = Asset::create([
                            'company_id' => $companyId,
                            'public_id' => AssetSequence::format($seq++),
                            'name' => (string) $name,
                            'category' => (string) ($this->cell($cells, $map, 'category') ?? 'غير مصنف'),
                            'branch_id' => $rowBranchId,
                            'cost' => $cost,
                            'book_value' => $cost,
                            'useful_life_months' => $usefulLife,
                            'serial' => $this->cell($cells, $map, 'serial') ?: null,
                            'case_type' => 'acc_register',
                            'status' => 'active',
                            'submitted_by_id' => $userId,
                            'purchased_at' => now(),
                        ]);
                        $imported[] = [
                            'id' => $asset->id,
                            'publicId' => $asset->public_id,
                            'name' => $asset->name,
                            'category' => $asset->category,
                            'branchId' => $asset->branch_id,
                            'cost' => $asset->cost,
                            'priceHalalas' => $asset->cost,
                            'bookValueHalalas' => $asset->book_value,
                        ];
                        $created++;
                    }
                    break; // first sheet only
                }
            });
            $reader->close();

            return $this->ok([
                'jobId' => 'job_'.strtoupper(bin2hex(random_bytes(6))),
                'parsedRows' => $parsed,
                'createdRows' => $created,
                'count' => $created,
                'imported' => $imported,
                'errors' => $errors,
            ], 202);
        });
    }

    /**
     * GET …/shifts/configs[?scope=brand|branch|all][&brandId=]
     *
     * «تعيين الشفتات» is assigned EITHER by brand or by branch, so the setup
     * screen reads both lists off one endpoint. Default stays `brand` — the
     * existing FE call is untouched.
     */
    public function shiftConfigs(Request $request, \Modules\Admin\Services\ShiftConfigService $configService): JsonResponse
    {
        return $this->run(function () use ($request, $configService) {
            $request->validate(['scope' => 'sometimes|in:brand,branch,all']);
            $scope = (string) $request->query('scope', 'brand');

            // Scope to the brands this accountant is responsible for, not every
            // brand in the company (client meeting: shift settings must load the
            // accountant's own brand, not a placeholder). null = admin/company-wide.
            $assignedBrandIds = $this->assignedBrandIds();
            $brands = AsabBrand::whereIn('company_id', $this->tenantCompanyIdsFor($request->user()))
                ->when($assignedBrandIds !== null, fn ($q) => $q->whereIn('id', $assignedBrandIds))
                ->when($request->query('brandId'), fn ($q, $id) => $q->where('id', $id))
                ->get();
            $configs = BrandShiftConfig::whereIn('brand_id', $brands->pluck('id'))->get()->keyBy('brand_id');

            $rows = [];
            if ($scope !== 'branch') {
                foreach ($brands as $brand) {
                    $rows[] = $configService->present($brand->id, $brand->name, $configs->get($brand->id));
                }
            }
            if ($scope !== 'brand') {
                $rows = array_merge($rows, $this->branchShiftConfigRows($brands, $configs, $configService));
            }

            return $this->listResponse($rows);
        });
    }

    /**
     * One row per branch of the in-scope brands: its own config when it has an
     * override, otherwise the brand's schedule with `hasOwnConfig=false` so the
     * screen can show «يتبع العلامة التجارية».
     *
     * @param  \Illuminate\Support\Collection<int, AsabBrand>  $brands
     * @param  \Illuminate\Support\Collection<string, BrandShiftConfig>  $brandConfigs
     * @return array<int, array<string, mixed>>
     */
    private function branchShiftConfigRows($brands, $brandConfigs, \Modules\Admin\Services\ShiftConfigService $configService): array
    {
        $assignedBranchIds = $this->assignedBranchIds();
        $rows = [];

        foreach ($brands as $brand) {
            // Brand → branches through the restaurant too (2026-08-03 rule).
            $branches = $this->brandBranches->branches($brand->id, ['id', 'name']);
            if ($assignedBranchIds !== null) {
                $branches = $branches->whereIn('id', $assignedBranchIds);
            }
            if ($branches->isEmpty()) {
                continue;
            }

            $own = \Modules\Admin\Models\BranchShiftConfig::whereIn('branch_id', $branches->pluck('id'))
                ->get()->keyBy('branch_id');

            foreach ($branches as $branch) {
                $rows[] = array_merge($configService->present(
                    $branch->id,
                    $branch->name,
                    $own->get($branch->id) ?? $brandConfigs->get($brand->id),
                    'branch',
                    $brand->id,
                    $own->has($branch->id),
                ), ['brandName' => $brand->name]);
            }
        }

        return $rows;
    }

    /**
     * PUT …/brands/{brandId}/shift-config — the meeting N-shift model (T08.1).
     * Accepts `{numShifts, durationHours, firstShiftStart, openingFloatHalalas}`,
     * the legacy `{morningWindow, eveningWindow, openingFloatHalalas}` pair, or a
     * `shiftOverrides[]` list (the ✏️ per-shift editor); persists the real
     * columns and emits computed windows + legacy aliases.
     *
     * `durationHours` is free text on the dashboard, so it is validated as a
     * NUMBER (7.5 is legal) and stored to the minute.
     */
    public function saveShiftConfig(Request $request, \Modules\Admin\Services\ShiftConfigService $configService, \Modules\Admin\Services\ShiftScheduleBridgeService $scheduleBridge, string $brandId): JsonResponse
    {
        return $this->run(function () use ($request, $configService, $scheduleBridge, $brandId) {
            $brand = AsabBrand::whereIn('company_id', $this->tenantCompanyIdsFor($request->user()))->findOrFail($brandId);
            // Zero-trust: a scoped accountant may only configure their assigned
            // brands, not any brand that merely shares the company.
            $this->assertBrandAssigned($brandId);
            $data = $request->validate($this->shiftConfigRules());

            $cfg = BrandShiftConfig::firstOrNew(['brand_id' => $brandId]);
            $cols = $configService->fromInput($data, $cfg->exists ? $cfg : null);
            // A schedule longer than a day wraps: two windows become identical
            // and the mobile app ends up with fewer shifts than the dashboard shows.
            $configService->assertFitsDayMinutes(
                (int) $cols['num_shifts'],
                (int) $cols['duration_minutes'],
                $configService->overrides($cols['shifts']),
            );

            $cfg->fill($cols)->save();

            // Project the schedule into the mobile `shifts` table so the app's
            // shift flows are seeded from the same config (bridge, not a mirror).
            $seeded = $scheduleBridge->regenerateForBrand($brandId);

            return $this->ok($configService->present($brandId, $brand->name, $cfg->fresh()) + ['mobileShiftsSeeded' => $seeded]);
        });
    }

    /**
     * PUT …/branches/{branchId}/shift-config — the same model assigned to ONE
     * branch. A branch row wins over its brand's schedule and is skipped by the
     * brand regenerate, so the two never fight over the mobile templates.
     */
    public function saveBranchShiftConfig(Request $request, \Modules\Admin\Services\ShiftConfigService $configService, \Modules\Admin\Services\ShiftScheduleBridgeService $scheduleBridge, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $configService, $scheduleBridge, $branchId) {
            // Zero-trust: out-of-scope branch ids read as absent (404), never as
            // «configurable».
            $this->assertBranchAssigned($branchId);
            $branch = \Modules\Branch\Models\Branch::whereIn('asab_company_id', $this->tenantCompanyIdsFor($request->user()))
                ->findOrFail($branchId);
            $data = $request->validate($this->shiftConfigRules());

            $cfg = \Modules\Admin\Models\BranchShiftConfig::firstOrNew(['branch_id' => $branchId]);
            // A first branch override starts from the brand's schedule, so saving
            // one field does not silently reset the branch to the defaults.
            $base = $cfg->exists
                ? $cfg
                : ($branch->asab_brand_id ? BrandShiftConfig::where('brand_id', $branch->asab_brand_id)->first() : null);

            $cols = $configService->fromInput($data, $base);
            $configService->assertFitsDayMinutes(
                (int) $cols['num_shifts'],
                (int) $cols['duration_minutes'],
                $configService->overrides($cols['shifts']),
            );

            $cfg->fill($cols)->save();
            $seeded = $scheduleBridge->regenerateForBranch($branchId);

            return $this->ok(array_merge(
                $configService->present($branchId, $branch->name, $cfg->fresh(), 'branch', $branch->asab_brand_id, true),
                ['mobileShiftsSeeded' => $seeded],
            ));
        });
    }

    /**
     * DELETE …/branches/{branchId}/shift-config — drop the override; the branch
     * follows its brand again (and is re-seeded from it).
     */
    public function deleteBranchShiftConfig(Request $request, \Modules\Admin\Services\ShiftConfigService $configService, \Modules\Admin\Services\ShiftScheduleBridgeService $scheduleBridge, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $configService, $scheduleBridge, $branchId) {
            $this->assertBranchAssigned($branchId);
            $branch = \Modules\Branch\Models\Branch::whereIn('asab_company_id', $this->tenantCompanyIdsFor($request->user()))
                ->findOrFail($branchId);

            \Modules\Admin\Models\BranchShiftConfig::where('branch_id', $branchId)->delete();
            $seeded = $scheduleBridge->regenerateForBranch($branchId);

            $brandCfg = $branch->asab_brand_id
                ? BrandShiftConfig::where('brand_id', $branch->asab_brand_id)->first()
                : null;

            return $this->ok(array_merge(
                $configService->present($branchId, $branch->name, $brandCfg, 'branch', $branch->asab_brand_id, false),
                ['mobileShiftsSeeded' => $seeded],
            ));
        });
    }

    /**
     * POST …/branches/{branchId}/shift-config/regenerate — re-project one
     * branch's effective schedule onto the mobile shift templates.
     */
    public function regenerateBranchShifts(Request $request, \Modules\Admin\Services\ShiftConfigService $configService, \Modules\Admin\Services\ShiftScheduleBridgeService $scheduleBridge, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $configService, $scheduleBridge, $branchId) {
            $this->assertBranchAssigned($branchId);
            $branch = \Modules\Branch\Models\Branch::whereIn('asab_company_id', $this->tenantCompanyIdsFor($request->user()))
                ->findOrFail($branchId);

            $cfg = \Modules\Admin\Models\BranchShiftConfig::where('branch_id', $branchId)->first();
            $effective = $cfg ?? ($branch->asab_brand_id ? BrandShiftConfig::where('brand_id', $branch->asab_brand_id)->first() : null);
            $seeded = $scheduleBridge->regenerateForBranch($branchId);

            return $this->ok(array_merge(
                $configService->present($branchId, $branch->name, $effective, 'branch', $branch->asab_brand_id, $cfg !== null),
                ['mobileShiftsSeeded' => $seeded],
            ));
        });
    }

    /**
     * Shared validation for both shift-config writers.
     *
     * @return array<string, mixed>
     */
    private function shiftConfigRules(): array
    {
        // Times are free text on the dashboard («06:00», «6:00», «6:00 AM») and
        // normalised by the service — but an impossible clock time is still a
        // 422, not a silent wrap to 01:59.
        $time = ['string', 'max:12', 'regex:/^\s*(\d|0\d|1\d|2[0-3])\s*(:\s*[0-5]\d)?\s*(am|pm|ص|م)?\s*$/iu'];

        return [
            'numShifts' => 'sometimes|integer|min:1|max:'.\Modules\Admin\Support\ShiftEnums::MAX_SHIFTS,
            // Free text: 8, 7.5, «٨» all land on the same stored minutes.
            'durationHours' => 'sometimes|numeric|min:0.25|max:24',
            'durationMinutes' => 'sometimes|integer|min:15|max:1440',
            'firstShiftStart' => array_merge(['sometimes'], $time),
            'openingFloatHalalas' => 'sometimes|integer|min:0',
            // Per-shift editor («اضغط ✏️ لتعديل وقت أي شفت بشكل منفرد»).
            'shiftOverrides' => 'sometimes|array|max:'.\Modules\Admin\Support\ShiftEnums::MAX_SHIFTS,
            'shiftOverrides.*.no' => 'required_with:shiftOverrides|integer|min:1|max:'.\Modules\Admin\Support\ShiftEnums::MAX_SHIFTS,
            'shiftOverrides.*.start' => array_merge(['sometimes'], $time),
            'shiftOverrides.*.durationHours' => 'sometimes|numeric|min:0.25|max:24',
            'shiftOverrides.*.durationMinutes' => 'sometimes|integer|min:15|max:1440',
            // Legacy pair — still accepted.
            'morningWindow' => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/'],
            'eveningWindow' => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/'],
        ];
    }

    /**
     * POST …/brands/{brandId}/shift-config/regenerate — the explicit «Regenerate»
     * action (FR-SHF-1): re-project the saved config onto the mobile shift
     * template rows for every branch of the brand, without changing the config.
     */
    public function regenerateShifts(Request $request, \Modules\Admin\Services\ShiftConfigService $configService, \Modules\Admin\Services\ShiftScheduleBridgeService $scheduleBridge, string $brandId): JsonResponse
    {
        return $this->run(function () use ($request, $configService, $scheduleBridge, $brandId) {
            $brand = AsabBrand::whereIn('company_id', $this->tenantCompanyIdsFor($request->user()))->findOrFail($brandId);
            $this->assertBrandAssigned($brandId);

            $cfg = BrandShiftConfig::where('brand_id', $brandId)->first();
            if ($cfg !== null) {
                $configService->assertFitsDayMinutes(
                    (int) $cfg->num_shifts,
                    $configService->durationMinutes($cfg),
                    $configService->overrides(is_array($cfg->shifts) ? $cfg->shifts : []),
                );
            }

            $seeded = $scheduleBridge->regenerateForBrand($brandId);

            return $this->ok($configService->present($brandId, $brand->name, $cfg) + ['mobileShiftsSeeded' => $seeded]);
        });
    }

    /** HEAD-4 monthly ledger: month filter + per-row running balance + typeLabel. */
    public function cashTransactions(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $custody = $this->scopeToAssignedBranches(CashCustody::whereIn('company_id', $this->tenantCompanyIdsFor($request->user())))->findOrFail($id);

            return $this->ok($this->custody->ledger(
                $custody,
                $request->query('month'),
                (int) $request->query('page', 1),
                (int) $request->query('pageSize', 50),
            ));
        });
    }

    /**
     * ACC-8.2 approve a pending disbursement txn — applies its balance effect
     * exactly once (idempotent: approving an already-approved txn is a no-op).
     * A rejected txn cannot be approved.
     */
    public function approveTransaction(Request $request, string $id, string $txnId): JsonResponse
    {
        return $this->run(function () use ($request, $id, $txnId) {
            $custody = $this->scopeToAssignedBranches(CashCustody::whereIn('company_id', $this->tenantCompanyIdsFor($request->user())))->findOrFail($id);
            $txn = CashTransaction::where('custody_id', $custody->id)->findOrFail($txnId);

            if ($txn->status === 'rejected') {
                throw new AsabException('TXN_REJECTED', 'A rejected transaction cannot be approved', 'لا يمكن اعتماد حركة مرفوضة', 409, ['status' => $txn->status]);
            }
            if ($txn->status === 'approved') {
                return $this->ok(['id' => $txn->id, 'status' => 'approved']); // already applied
            }

            DB::transaction(function () use ($custody, $txn) {
                if ($txn->txn_type === 'debit') {
                    $this->custody->assertNotOverdrawn($custody, (int) $txn->amount);
                }
                $txn->update(['status' => 'approved']);
                $this->custody->applyTxn($custody, $txn);
            });

            return $this->ok(['id' => $txn->id, 'status' => 'approved']);
        });
    }

    /**
     * ACC-8.2 reject a txn — persists the reason and reverses the balance effect
     * if the txn was previously approved (pending txns applied nothing to undo).
     */
    public function rejectTransaction(Request $request, string $id, string $txnId): JsonResponse
    {
        return $this->run(function () use ($request, $id, $txnId) {
            $data = $request->validate(['reason' => 'required|string|max:255']);
            $custody = $this->scopeToAssignedBranches(CashCustody::whereIn('company_id', $this->tenantCompanyIdsFor($request->user())))->findOrFail($id);
            $txn = CashTransaction::where('custody_id', $custody->id)->findOrFail($txnId);

            if ($txn->status === 'rejected') {
                return $this->ok(['id' => $txn->id, 'status' => 'rejected']);
            }

            DB::transaction(function () use ($custody, $txn, $data) {
                $wasApplied = $txn->status === 'approved';
                $txn->update(['status' => 'rejected', 'reason' => $data['reason']]);
                if ($wasApplied) {
                    $this->custody->reverseTxn($custody, $txn);
                }
            });

            return $this->ok(['id' => $txn->id, 'status' => 'rejected']);
        });
    }

    /**
     * ACC-8 / HEAD-4 settle a custody: zero `used`, post the settlement (and any
     * new-deposit) txn to the ledger for audit, drain pending settlement requests,
     * and recompute status — all atomically.
     */
    public function settleCustody(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['newDepositHalalas' => 'sometimes|integer|min:0']);
            $custody = $this->scopeToAssignedBranches(CashCustody::whereIn('company_id', $this->tenantCompanyIdsFor($request->user())))->findOrFail($id);

            DB::transaction(function () use ($custody, $data, $request) {
                $usedBefore = (int) $custody->used;
                $deposit = (int) ($data['newDepositHalalas'] ?? 0);

                // Audit trail: a settlement debit clears the spent portion; an
                // optional deposit credit records the top-up.
                if ($usedBefore > 0) {
                    CashTransaction::create([
                        'custody_id' => $custody->id, 'txn_type' => 'debit', 'amount' => $usedBefore,
                        'description' => 'تسوية العهدة — إغلاق المصروف', 'txn_date' => now(),
                        'status' => 'approved', 'source' => 'manual', 'created_by_id' => $request->user()->id,
                    ]);
                }
                if ($deposit > 0) {
                    CashTransaction::create([
                        'custody_id' => $custody->id, 'txn_type' => 'credit', 'amount' => $deposit,
                        'description' => 'تسوية العهدة — إيداع جديد', 'txn_date' => now(),
                        'status' => 'approved', 'source' => 'treasury', 'created_by_id' => $request->user()->id,
                    ]);
                }

                $custody->update([
                    'used' => 0, 'last_settlement_at' => now(), 'days_since_settlement' => 0,
                    'amount' => (int) $custody->amount + $deposit,
                ]);

                // Close any pending settlement requests this settlement fulfils.
                SettlementRequest::where('custody_id', $custody->id)->where('status', 'pending')
                    ->update(['status' => 'approved', 'approved_at' => now()]);

                $this->custody->recompute($custody->refresh(), notify: false);
            });

            $custody->refresh();

            return $this->ok(['id' => $custody->id, 'amountHalalas' => (int) $custody->amount, 'usedHalalas' => 0]);
        });
    }

    private function expenseOp(Request $request, string $invoiceId): Operation
    {
        return $this->scopeToAssignedBranches(Operation::whereIn('company_id', $this->tenantCompanyIdsFor($request->user())))
            ->where(fn ($q) => $q->where('id', $invoiceId)->orWhere('public_id', $invoiceId))
            ->where('module_key', 'expenses')->firstOrFail();
    }

    private function latestInventoryOp(Request $request, string $branchId): ?Operation
    {
        $this->assertBranchAssigned($branchId);

        return Operation::whereIn('company_id', $this->tenantCompanyIdsFor($request->user()))->where('branch_id', $branchId)
            ->where('module_key', 'inventory')->orderByDesc('operation_date')->first();
    }
}
