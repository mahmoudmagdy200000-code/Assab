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
use Modules\Admin\Services\SalesVarianceService;

/**
 * Company-scoped Accountant surface — the endpoints with NEW logic beyond what
 * the shared Accountant controllers already provide (COMPANY_DASHBOARD_API_SPEC.md §5.3).
 */
class AccountantCompanyController extends AsabController
{
    public function __construct(private readonly SalesVarianceService $salesVariance) {}

    public function dashboard(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $base = fn () => Operation::where('company_id', $companyId);
            $byModule = $base()->where('status', Operation::STATUS_PENDING)
                ->selectRaw('module_key, COUNT(*) as c')->groupBy('module_key')->pluck('c', 'module_key');

            return $this->ok([
                'today' => now()->toDateString(),
                'counts' => [
                    'awaitingReview' => $base()->where('status', Operation::STATUS_PENDING)->count(),
                    'iApproved' => $base()->where('approved_by_id', $request->user()->id)->count(),
                    'finalApproved' => $base()->where('status', Operation::STATUS_FINAL)->count(),
                    'rejected' => $base()->where('status', Operation::STATUS_REJECTED)->count(),
                    'approvalRatePct' => 100,
                ],
                'pendingByModule' => $byModule,
                'needsAttention' => $this->needsAttention($base()),
                'rejectedReuploadNeededCount' => $base()->where('status', Operation::STATUS_REJECTED)->count(),
            ]);
        });
    }

    private const MODULE_LABELS = [
        'sales' => 'المبيعات', 'expenses' => 'المصروفات', 'purchases' => 'المشتريات', 'inventory' => 'المخزون',
        'shifts' => 'الورديات', 'employees' => 'الموظفين', 'cash' => 'النقدية', 'waste' => 'الهدر',
    ];

    /** @return array<int, array<string, mixed>> spec §5.3.1 needsAttention shape */
    private function needsAttention($base): array
    {
        $ops = (clone $base)->where('match', 'diff')->whereIn('status', ['pending', 'approved'])->limit(10)->get();
        $branchNames = \Modules\Branch\Models\Branch::whereIn('id', $ops->pluck('branch_id')->filter()->unique())->pluck('name', 'id');

        return $ops->map(fn (Operation $o) => [
            'operationId' => $o->id, 'refNum' => $o->public_id, 'branch' => $branchNames[$o->branch_id] ?? '—',
            'moduleLabel' => self::MODULE_LABELS[$o->module_key] ?? $o->module_key, 'match' => $o->match, 'diff' => $o->diff_note,
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
            $op = Operation::where('company_id', $request->user()->company_id)
                ->where(fn ($q) => $q->where('id', $id)->orWhere('public_id', $id))->where('module_key', 'sales')->firstOrFail();

            return $this->ok($this->salesVariance->assign($op, $data['allocations'], $data['notes'] ?? null, $request->user()));
        });
    }

    public function employeeLookup(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $num = $request->query('empNumber');
            $emp = Employee::where('company_id', $request->user()->company_id)->where('branch_id', $branchId)
                ->where('emp_number', $num)->first();
            if (! $emp) {
                throw new AsabException('NOT_FOUND', 'Employee not found', 'الموظف غير موجود', 404);
            }

            return $this->ok(['empNumber' => $emp->emp_number, 'name' => $emp->name]);
        });
    }

    public function verifyExpense(Request $request, string $invoiceId): JsonResponse
    {
        return $this->run(function () use ($request, $invoiceId) {
            $op = $this->expenseOp($request, $invoiceId);
            $payload = $op->payload ?? [];
            $payload['verified'] = true;
            $payload['verifiedAt'] = now()->toIso8601String();
            $payload['verifiedBy'] = $request->user()->id;
            $op->update(['payload' => $payload]);

            return $this->ok(['id' => $op->id, 'verified' => true, 'verifiedAt' => $payload['verifiedAt']]);
        });
    }

    public function unverifyExpense(Request $request, string $invoiceId): JsonResponse
    {
        return $this->run(function () use ($request, $invoiceId) {
            $op = $this->expenseOp($request, $invoiceId);
            $payload = $op->payload ?? [];
            unset($payload['verified'], $payload['verifiedAt'], $payload['verifiedBy']);
            $op->update(['payload' => $payload]);

            return $this->noContent();
        });
    }

    public function expenseAttachments(Request $request, string $invoiceId): JsonResponse
    {
        return $this->run(function () use ($request, $invoiceId) {
            $op = $this->expenseOp($request, $invoiceId);

            return $this->listResponse(($op->payload['attachments'] ?? []));
        });
    }

    public function inventorySendNotification(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $branchId) {
            $data = $request->validate(['itemIndices' => 'sometimes|array', 'note' => 'sometimes|string']);
            $op = $this->latestInventoryOp($request, $branchId);
            if ($op) {
                $payload = $op->payload ?? [];
                $payload['notifSentAt'] = now()->toIso8601String();
                $op->update(['payload' => $payload]);
            }
            $rt->inventoryFlagSent($branchId, $data['itemIndices'] ?? []);

            return $this->ok(['notifSentAt' => now()->toIso8601String()]);
        });
    }

    public function inventoryMarkConfirmed(Request $request, string $branchId): JsonResponse
    {
        return $this->run(function () use ($request, $branchId) {
            $op = $this->latestInventoryOp($request, $branchId);
            if ($op) {
                $payload = $op->payload ?? [];
                $payload['isConfirmed'] = true;
                $op->update(['payload' => $payload]);
            }

            return $this->ok(['branchId' => $branchId, 'isConfirmed' => true]);
        });
    }

    public function updateAsset(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $asset = Asset::where('company_id', $request->user()->company_id)->findOrFail($id);
            $data = $request->validate([
                'name' => 'sometimes|string|max:200', 'category' => 'sometimes|string|max:32', 'custodian' => 'sometimes|nullable|string|max:200',
                'status' => 'sometimes|string|max:24', 'bookValue' => 'sometimes|integer|min:0',
            ]);
            $asset->update(array_filter([
                'name' => $data['name'] ?? null, 'category' => $data['category'] ?? null,
                'custodian' => $data['custodian'] ?? null, 'status' => $data['status'] ?? null, 'book_value' => $data['bookValue'] ?? null,
            ], fn ($v) => $v !== null));

            return $this->ok(['id' => $asset->id, 'name' => $asset->name, 'status' => $asset->status, 'bookValue' => $asset->book_value]);
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

            DB::transaction(function () use ($reader, &$map, &$parsed, &$created, $companyId, $userId) {
                $seq = (int) (Asset::withoutGlobalScopes()->where('company_id', $companyId)->count());
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
                        $cost = $this->toHalalas($this->cell($cells, $map, 'cost'));
                        Asset::create([
                            'company_id' => $companyId,
                            'public_id' => 'FA-'.str_pad((string) (++$seq), 4, '0', STR_PAD_LEFT),
                            'name' => (string) $name,
                            'category' => (string) ($this->cell($cells, $map, 'category') ?? 'غير مصنف'),
                            'branch_id' => $this->cell($cells, $map, 'branchId') ?: null,
                            'cost' => $cost,
                            'book_value' => $cost,
                            'useful_life_months' => (int) ($this->cell($cells, $map, 'usefulLife') ?? 0) ?: null,
                            'case_type' => 'acc_register',
                            'status' => 'active',
                            'submitted_by_id' => $userId,
                            'purchased_at' => now(),
                        ]);
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
            $custody = CashCustody::where('company_id', $request->user()->company_id)->findOrFail($id);
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
            $custody = CashCustody::where('company_id', $request->user()->company_id)->findOrFail($id);
            $txn = CashTransaction::where('custody_id', $custody->id)->findOrFail($txnId);
            $txn->update(['status' => 'approved']);

            return $this->ok(['id' => $txn->id, 'status' => 'approved']);
        });
    }

    public function rejectTransaction(Request $request, string $id, string $txnId): JsonResponse
    {
        return $this->run(function () use ($request, $id, $txnId) {
            $request->validate(['reason' => 'required|string|max:255']);
            $custody = CashCustody::where('company_id', $request->user()->company_id)->findOrFail($id);
            $txn = CashTransaction::where('custody_id', $custody->id)->findOrFail($txnId);
            $txn->update(['status' => 'rejected']);

            return $this->ok(['id' => $txn->id, 'status' => 'rejected']);
        });
    }

    public function settleCustody(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['newDepositHalalas' => 'sometimes|integer|min:0']);
            $custody = CashCustody::where('company_id', $request->user()->company_id)->findOrFail($id);
            $custody->update([
                'used' => 0, 'last_settlement_at' => now(), 'days_since_settlement' => 0,
                'amount' => $custody->amount + ($data['newDepositHalalas'] ?? 0),
            ]);

            return $this->ok(['id' => $custody->id, 'amountHalalas' => $custody->amount, 'usedHalalas' => 0]);
        });
    }

    private function expenseOp(Request $request, string $invoiceId): Operation
    {
        return Operation::where('company_id', $request->user()->company_id)
            ->where(fn ($q) => $q->where('id', $invoiceId)->orWhere('public_id', $invoiceId))
            ->where('module_key', 'expenses')->firstOrFail();
    }

    private function latestInventoryOp(Request $request, string $branchId): ?Operation
    {
        return Operation::where('company_id', $request->user()->company_id)->where('branch_id', $branchId)
            ->where('module_key', 'inventory')->orderByDesc('operation_date')->first();
    }
}
