<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\AccountantDashboardService;
use Modules\Admin\Services\AssetSequence;
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
    public function __construct(
        private readonly SalesVarianceService $salesVariance,
        private readonly AccountantDashboardService $dashboards,
        private readonly SalesKpiService $salesKpis,
        private readonly SalesCompletenessService $completeness,
        private readonly ExpenseInvoiceService $invoices,
        private readonly ExpenseKpiService $expenseKpi,
        private readonly TenantContext $tenant,
    ) {}

    /** GET …/sales/kpis — ACC-1.1 cards + ACC-1.5 variance banner. */
    public function salesKpis(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate(['date' => 'sometimes|date']);

            return $this->ok($this->salesKpis->forDate(
                $request->user()->company_id,
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
                $request->user()->company_id,
                $this->assignedBranchIds(),
                (int) $request->query('days', 7),
            ));
        });
    }

    /** GET /company/me/accountant/dashboard — ACC-0 «ملخص اليوم». */
    public function dashboard(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $branchIds = $this->assignedBranchIds();
            $actor = $request->user();
            $base = fn () => $this->scopeToAssignedBranches(Operation::where('company_id', $companyId));

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
            $op = $this->scopeToAssignedBranches(Operation::where('company_id', $request->user()->company_id))
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
            $emp = $this->scopeToAssignedBranches(Employee::where('company_id', $request->user()->company_id))
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
                $request->user()->company_id,
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
            $asset = $this->scopeToAssignedBranches(Asset::where('company_id', $request->user()->company_id))->findOrFail($id);
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

    /** Build a column-index → field map from a header row (Arabic/English tolerant). */
    private function mapAssetHeaders(array $headers): array
    {
        $aliases = [
            'name' => ['name', 'asset', 'الاسم', 'اسم', 'الأصل', 'اسم الأصل'],
            'category' => ['category', 'الفئة', 'التصنيف', 'النوع'],
            'cost' => ['cost', 'value', 'price', 'amount', 'القيمة', 'التكلفة', 'السعر', 'المبلغ'],
            'branchId' => ['branchid', 'branch', 'الفرع', 'فرع'],
            'usefulLife' => ['usefullife', 'useful_life_months', 'life', 'العمر', 'العمر الإنتاجي', 'العمر الانتاجي'],
            'serial' => ['serial', 'serialnumber', 'الرقم التسلسلي', 'السيريال'],
        ];
        $map = [];
        foreach ($headers as $idx => $h) {
            $norm = mb_strtolower(trim((string) $h));
            foreach ($aliases as $field => $names) {
                if (in_array($norm, $names, true) || in_array(str_replace(' ', '', $norm), $names, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    private function cell(array $cells, array $map, string $field): mixed
    {
        return isset($map[$field]) ? ($cells[$map[$field]] ?? null) : null;
    }

    /** A spreadsheet money value (SAR, possibly decimal) → integer halalas. */
    private function toHalalas(mixed $v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        $n = (float) preg_replace('/[^0-9.\-]/', '', (string) $v);

        return (int) round($n * 100);
    }

    public function shiftConfigs(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $brands = AsabBrand::where('company_id', $request->user()->company_id)->get();
            $configs = BrandShiftConfig::whereIn('brand_id', $brands->pluck('id'))->get()->keyBy('brand_id');

            return $this->listResponse($brands->map(function (AsabBrand $b) use ($configs) {
                $c = $configs->get($b->id);
                $s = $c?->shifts ?? [];

                return [
                    'brandId' => $b->id, 'brandName' => $b->name,
                    'morningWindow' => $s['morningWindow'] ?? '06:00-14:00',
                    'eveningWindow' => $s['eveningWindow'] ?? '14:00-23:00',
                    'openingFloatHalalas' => $s['openingFloatHalalas'] ?? 0,
                ];
            })->all());
        });
    }

    public function saveShiftConfig(Request $request, string $brandId): JsonResponse
    {
        return $this->run(function () use ($request, $brandId) {
            AsabBrand::where('company_id', $request->user()->company_id)->findOrFail($brandId);
            $data = $request->validate([
                'morningWindow' => 'required|string|max:32', 'eveningWindow' => 'required|string|max:32', 'openingFloatHalalas' => 'required|integer|min:0',
            ]);
            $cfg = BrandShiftConfig::firstOrNew(['brand_id' => $brandId]);
            $cfg->shifts = ['morningWindow' => $data['morningWindow'], 'eveningWindow' => $data['eveningWindow'], 'openingFloatHalalas' => $data['openingFloatHalalas']];
            $cfg->save();

            return $this->ok(array_merge(['brandId' => $brandId], $cfg->shifts));
        });
    }

    public function cashTransactions(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $custody = $this->scopeToAssignedBranches(CashCustody::where('company_id', $request->user()->company_id))->findOrFail($id);
            $txns = CashTransaction::where('custody_id', $custody->id)->orderByDesc('txn_date')->get();

            return $this->ok([
                'custody' => ['id' => $custody->id, 'custodianName' => $custody->custodian_name, 'amountHalalas' => $custody->amount, 'usedHalalas' => $custody->used],
                'transactions' => $txns->map(fn (CashTransaction $t) => [
                    'id' => $t->id, 'date' => optional($t->txn_date)->toIso8601String(), 'description' => $t->description,
                    'type' => $t->txn_type, 'amountHalalas' => $t->amount, 'status' => $t->status,
                ])->all(),
            ]);
        });
    }

    public function approveTransaction(Request $request, string $id, string $txnId): JsonResponse
    {
        return $this->run(function () use ($request, $id, $txnId) {
            $custody = $this->scopeToAssignedBranches(CashCustody::where('company_id', $request->user()->company_id))->findOrFail($id);
            $txn = CashTransaction::where('custody_id', $custody->id)->findOrFail($txnId);
            $txn->update(['status' => 'approved']);

            return $this->ok(['id' => $txn->id, 'status' => 'approved']);
        });
    }

    public function rejectTransaction(Request $request, string $id, string $txnId): JsonResponse
    {
        return $this->run(function () use ($request, $id, $txnId) {
            $request->validate(['reason' => 'required|string|max:255']);
            $custody = $this->scopeToAssignedBranches(CashCustody::where('company_id', $request->user()->company_id))->findOrFail($id);
            $txn = CashTransaction::where('custody_id', $custody->id)->findOrFail($txnId);
            $txn->update(['status' => 'rejected']);

            return $this->ok(['id' => $txn->id, 'status' => 'rejected']);
        });
    }

    public function settleCustody(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['newDepositHalalas' => 'sometimes|integer|min:0']);
            $custody = $this->scopeToAssignedBranches(CashCustody::where('company_id', $request->user()->company_id))->findOrFail($id);
            $custody->update([
                'used' => 0, 'last_settlement_at' => now(), 'days_since_settlement' => 0,
                'amount' => $custody->amount + ($data['newDepositHalalas'] ?? 0),
            ]);

            return $this->ok(['id' => $custody->id, 'amountHalalas' => $custody->amount, 'usedHalalas' => 0]);
        });
    }

    private function expenseOp(Request $request, string $invoiceId): Operation
    {
        return $this->scopeToAssignedBranches(Operation::where('company_id', $request->user()->company_id))
            ->where(fn ($q) => $q->where('id', $invoiceId)->orWhere('public_id', $invoiceId))
            ->where('module_key', 'expenses')->firstOrFail();
    }

    private function latestInventoryOp(Request $request, string $branchId): ?Operation
    {
        $this->assertBranchAssigned($branchId);

        return Operation::where('company_id', $request->user()->company_id)->where('branch_id', $branchId)
            ->where('module_key', 'inventory')->orderByDesc('operation_date')->first();
    }
}
