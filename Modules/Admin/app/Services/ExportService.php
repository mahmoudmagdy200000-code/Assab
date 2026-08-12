<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\AuditLog;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Reminder;
use Modules\Admin\Models\Shift;
use Modules\Admin\Models\SupplierItem;
use Modules\Admin\Support\AssetEnums;
use Modules\Admin\Support\SalesChannels;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synchronous spreadsheet export generator (COMPANY_DASHBOARD_API_SPEC.md §5.3).
 * Builds real xlsx/csv binaries with openspout. All queries inherit the tenant
 * global scope (BelongsToTenant), so a company user only ever exports its own data.
 */
class ExportService
{
    public function __construct(
        private readonly BrandBranchResolver $brandBranches,
        private readonly CustodyService $custody,
    ) {}

    /** Write headings + rows to a temp file and return a self-deleting download response. */
    public function make(string $format, string $filename, array $headings, array $rows): BinaryFileResponse
    {
        $format = in_array($format, ['xlsx', 'csv'], true) ? $format : 'xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'asab_export_');

        $writer = $format === 'csv' ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues($headings));
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues(array_values($r)));
        }
        $writer->close();

        $mime = $format === 'csv'
            ? 'text/csv; charset=UTF-8'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()
            ->download($tmp, $filename.'-'.now()->format('Ymd-His').'.'.$format, ['Content-Type' => $mime])
            ->deleteFileAfterSend(true);
    }

    /** Halalas integer → SAR decimal string for human-readable sheets. */
    private function sar(int|float|null $halalas): string
    {
        return number_format(((int) $halalas) / 100, 2, '.', '');
    }

    /** Map of branch id → name for the company's branches (legacy Branch table). */
    private function branchNames(iterable $ids): \Illuminate\Support\Collection
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return \Modules\Branch\Models\Branch::whereIn('id', $ids)->pluck('name', 'id');
    }

    /** Map of user id → name (submitters / reviewers). */
    private function userNames(iterable $ids): \Illuminate\Support\Collection
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return AsabUser::whereIn('id', $ids)->pluck('name', 'id');
    }

    /** Map of supplier id → name. */
    private function supplierNames(iterable $ids): \Illuminate\Support\Collection
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return AsabSupplier::whereIn('id', $ids)->pluck('name', 'id');
    }

    /** Map of branch id → brand name (operations carry no brand column). */
    private function brandNamesByBranch(iterable $branchIds): \Illuminate\Support\Collection
    {
        $branchToBrand = $this->branchToBrandId($branchIds);
        if ($branchToBrand->isEmpty()) {
            return collect();
        }
        $brandNames = AsabBrand::whereIn('id', $branchToBrand->filter()->unique()->values())->pluck('name', 'id');

        return $branchToBrand->map(fn ($brandId) => $brandId ? ($brandNames[$brandId] ?? '—') : '—');
    }

    /** Map of branch id → brand id (legacy Branch table). */
    private function branchToBrandId(iterable $branchIds): \Illuminate\Support\Collection
    {
        $branchIds = collect($branchIds)->filter()->unique()->values();
        if ($branchIds->isEmpty()) {
            return collect();
        }

        return \Modules\Branch\Models\Branch::whereIn('id', $branchIds)->pluck('asab_brand_id', 'id');
    }

    /**
     * GET /company/me/inventory/export — variance sheet across the company's
     * branches (FE completion request §1.8). Same variance math as the
     * reconciliation snapshot; one row per counted item.
     *
     * @param  array{brandId?:?string, branchId?:?string, date?:?string}  $filters
     */
    public function inventory(string $format, string|array $companyId, array $filters): BinaryFileResponse
    {
        $q = Operation::whereIn('company_id', (array) $companyId)->where('module_key', 'inventory');

        if (! empty($filters['branchId'])) {
            $q->where('branch_id', $filters['branchId']);
        } elseif (! empty($filters['brandId'])) {
            $branchIds = \Modules\Branch\Models\Branch::where('asab_brand_id', $filters['brandId'])->pluck('id');
            $q->whereIn('branch_id', $branchIds);
        }
        if (! empty($filters['date'])) {
            $q->whereDate('operation_date', $filters['date']);
        }

        $ops = $q->orderByDesc('operation_date')->get();
        $branchNames = $this->branchNames($ops->pluck('branch_id'));
        $userNames = $this->userNames($ops->pluck('submitted_by_id'));

        $rows = [];
        foreach ($ops as $op) {
            $items = is_array($op->payload['items'] ?? null) ? $op->payload['items'] : [];
            $countedAt = optional($op->operation_date)->toIso8601String();
            $countedBy = $op->payload['countedBy'] ?? ($userNames[$op->submitted_by_id] ?? '—');

            foreach ($items as $it) {
                $expected = (float) ($it['expectedQty'] ?? $it['expected'] ?? $it['systemQty'] ?? 0);
                $actual = (float) ($it['actualQty'] ?? $it['actual'] ?? $it['countedQty'] ?? 0);
                $varianceQty = round($expected - $actual, 3);
                $variancePct = $expected != 0.0 ? round($varianceQty / $expected * 100, 2) : 0.0;
                $unitPrice = (int) ($it['unitPriceHalalas'] ?? $it['priceHalalas'] ?? 0);
                $varianceValue = (int) round(abs($varianceQty) * $unitPrice);

                $rows[] = [
                    $branchNames[$op->branch_id] ?? $op->branch_id,
                    $it['name'] ?? ($it['itemName'] ?? '—'),
                    $it['category'] ?? '—',
                    $it['unit'] ?? '—',
                    $expected,
                    $actual,
                    $varianceQty,
                    $variancePct,
                    $this->sar($varianceValue),
                    $countedAt,
                    $countedBy,
                ];
            }
        }

        return $this->make($format, 'inventory-variance', [
            'Branch', 'Item', 'Category', 'Unit', 'Expected Qty', 'Actual Qty',
            'Variance Qty', 'Variance Pct', 'Variance Value (SAR)', 'Last Counted At', 'Counted By',
        ], $rows);
    }

    /**
     * Single-operation detail sheet.
     *
     * `$branchIds` is the caller's assigned-branch scope (null = company-wide):
     * an operation outside it must read as absent, exactly like the API's
     * scoped `firstOrFail`.
     *
     * @param  string[]|null  $branchIds
     */
    public function operation(string $format, string $opId, ?array $branchIds = null): BinaryFileResponse
    {
        $op = Operation::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->where(fn ($q) => $q->where('id', $opId)->orWhere('public_id', $opId))
            ->firstOrFail();
        $branch = $this->branchNames([$op->branch_id])->get($op->branch_id, '—');

        $headings = ['البند', 'القيمة'];
        $rows = [
            ['رقم العملية', $op->public_id],
            ['الوحدة', $op->module_key],
            ['الفرع', $branch],
            ['المبلغ (ر.س)', $this->sar($op->amount)],
            ['الحالة', $op->status],
            ['المطابقة', $op->match],
            ['التاريخ', optional($op->operation_date)->toDateString() ?? optional($op->created_at)->toDateString()],
        ];

        $payload = $op->payload ?? [];

        // Sales: the channel reconciliation is the detail that matters.
        $channels = $payload['reconciliation']['channels'] ?? [];
        if (is_array($channels) && $channels !== []) {
            $rows[] = ['', ''];
            $rows[] = ['قناة التحصيل', 'المبلغ المُدخل (ر.س)'];
            foreach ($channels as $channel) {
                $rows[] = [SalesChannels::labelAr($channel['key']), $this->sar((int) ($channel['actualAmountHalalas'] ?? 0))];
            }
            $rows[] = ['إجمالي التحصيل', $this->sar((int) ($payload['reconciliation']['totalCollectionHalalas'] ?? 0))];
            $rows[] = ['الفرق', $this->sar((int) ($payload['reconciliation']['varianceHalalas'] ?? 0))];
        }

        // Append per-module line items from the payload so the sheet carries detail, not just the envelope.
        $lines = $payload['invoices'] ?? ($payload['products'] ?? ($payload['items'] ?? []));
        if (is_array($lines) && $lines !== []) {
            $rows[] = ['', ''];
            $rows[] = ['تفاصيل', ''];
            foreach ($lines as $i => $line) {
                $label = $line['invNum'] ?? ($line['name'] ?? ($line['product'] ?? ('بند '.($i + 1))));
                $val = $line['amountAfterTax'] ?? ($line['amount'] ?? ($line['value'] ?? ($line['qty'] ?? '')));
                $rows[] = [(string) $label, is_numeric($val) && $val > 999 ? $this->sar((int) $val) : (string) $val];
            }
        }

        return $this->make($format, 'operation-'.$op->public_id, $headings, $rows);
    }

    public function waste(string $format, ?string $branchId, ?array $branchIds = null): BinaryFileResponse
    {
        $q = Operation::where('module_key', 'waste');
        if ($branchIds !== null) {
            $q->whereIn('branch_id', $branchIds);
        }
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        $ops = $q->orderByDesc('operation_date')->limit(5000)->get();
        $branchNames = $this->branchNames($ops->pluck('branch_id'));

        $headings = ['رقم العملية', 'الفرع', 'التاريخ', 'المنتج', 'التصنيف', 'الكمية', 'القيمة (ر.س)', 'المسؤولية'];
        $rows = [];
        foreach ($ops as $op) {
            $products = $op->payload['products'] ?? [];
            $date = optional($op->operation_date)->toDateString() ?? optional($op->created_at)->toDateString();
            if (! is_array($products) || $products === []) {
                $rows[] = [$op->public_id, $branchNames[$op->branch_id] ?? '—', $date, '—', '—', '', $this->sar($op->amount), '—'];

                continue;
            }
            foreach ($products as $p) {
                $rows[] = [
                    $op->public_id,
                    $branchNames[$op->branch_id] ?? '—',
                    $date,
                    $p['name'] ?? '—',
                    $p['classification'] ?? '—',
                    (string) ($p['qty'] ?? ''),
                    $this->sar($p['value'] ?? 0),
                    $p['responsibility'] ?? '—',
                ];
            }
        }

        return $this->make($format, 'waste', $headings, $rows);
    }

    public function shifts(string $format, ?string $branchId, ?array $branchIds = null): BinaryFileResponse
    {
        // Till shifts only — the columns below (expected/actual cash, variance)
        // are empty by construction on a manager's mirrored workday.
        $q = Shift::cashierRole()->where('status', 'closed');
        // Zero-trust: a branch-scoped accountant exports only their branches.
        if ($branchIds !== null) {
            $q->whereIn('branch_id', $branchIds);
        }
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        $shifts = $q->orderByDesc('ended_at')->limit(5000)->get();
        $branchNames = $this->branchNames($shifts->pluck('branch_id'));

        $headings = ['الفرع', 'نوع الشفت', 'المشرف', 'الكاشير', 'البداية', 'النهاية', 'عدد الطلبات', 'المبيعات (ر.س)', 'النقد المتوقع', 'النقد الفعلي', 'الفرق'];
        $rows = $shifts->map(fn (Shift $s) => [
            $branchNames[$s->branch_id] ?? '—',
            $s->shift_type ?? '—',
            $s->supervisor_name ?? '—',
            $s->cashier_name ?? '—',
            optional($s->started_at)->toDateTimeString(),
            optional($s->ended_at)->toDateTimeString(),
            (string) $s->orders_count,
            $this->sar($s->sales_amount),
            $this->sar($s->cash_expected),
            $this->sar($s->cash_actual),
            $this->sar($s->variance),
        ])->all();

        return $this->make($format, 'shifts', $headings, $rows);
    }

    /**
     * ACC-7 payroll sheet. Net = salary − Σdebits + Σcredits: a debit (سلفة/خصم)
     * reduces net pay, a credit (مكافأة/تسوية) raises it. Columns split advances
     * from other deductions and surface bonuses. Branch-scoped for zero-trust.
     */
    public function payroll(string $format, ?string $month, ?array $branchIds = null, ?string $brandId = null): BinaryFileResponse
    {
        $month = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $employees = Employee::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->tap(fn ($q) => $this->brandBranches->applyFilter($q, $brandId))
            ->orderBy('emp_number')->limit(10000)->get();
        $branchNames = $this->branchNames($employees->pluck('branch_id'));

        // One aggregate query for credits/debits in the month, grouped by employee.
        $movements = EmployeeMovement::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('movement_date', [$start, $end])->get()->groupBy('employee_id');

        $headings = ['رقم الموظف', 'الاسم', 'الفرع', 'الوظيفة', 'الراتب (ر.س)', 'السلف (ر.س)', 'الخصومات (ر.س)', 'المكافآت (ر.س)', 'الصافي (ر.س)', 'الحالة'];
        $rows = $employees->map(function (Employee $e) use ($branchNames, $movements) {
            $mv = $movements->get($e->id, collect());
            $debits = $mv->where('movement_type', 'debit');
            $advances = (int) $debits->where('category', 'advance')->sum('amount');
            $otherDeductions = (int) $debits->where('category', '!=', 'advance')->sum('amount');
            $credits = (int) $mv->where('movement_type', 'credit')->sum('amount');
            $net = (int) $e->monthly_salary - $advances - $otherDeductions + $credits;

            return [
                $e->emp_number,
                $e->name,
                $branchNames[$e->branch_id] ?? '—',
                $e->role ?? '—',
                $this->sar($e->monthly_salary),
                $this->sar($advances),
                $this->sar($otherDeductions),
                $this->sar($credits),
                $this->sar($net),
                $e->status === 'active' ? 'نشط' : 'موقوف',
            ];
        })->all();

        return $this->make($format, 'payroll-'.$month, $headings, $rows);
    }

    /** HEAD-4 custody monthly ledger sheet (rows mirror the JSON ledger). */
    public function custodyLedger(string $format, array $ledger): BinaryFileResponse
    {
        $headings = ['التاريخ', 'الوصف', 'النوع', 'المبلغ (ر.س)', 'الحالة', 'الرصيد الجاري (ر.س)'];
        $rows = array_map(fn (array $t) => [
            $t['txnDate'] ? Carbon::parse($t['txnDate'])->toDateString() : '—',
            $t['description'] ?? '—',
            $t['typeLabelAr'] ?? $t['txnType'],
            $this->sar($t['amountHalalas']),
            $t['status'] ?? '—',
            $this->sar($t['runningBalanceHalalas']),
        ], $ledger['transactions']);

        $rows[] = ['', 'الرصيد الحالي', '', '', '', $this->sar($ledger['custody']['currentBalanceHalalas'])];
        $label = 'custody-ledger-'.substr($ledger['custody']['id'], 0, 8).'-'.$ledger['period']['month'];

        return $this->make($format, $label, $headings, $rows);
    }

    /** ACC-7.2 per-employee monthly statement sheet (rows mirror the JSON statement). */
    /**
     * ACC-7.2 «كشف حساب الموظف». `$format` accepts `pdf` beside xlsx/csv —
     * the statement is the one sheet the accountant prints and hands over, so
     * it ships a real RTL PDF rather than a spreadsheet only.
     */
    public function employeeStatement(string $format, array $statement): BinaryFileResponse|Response
    {
        if ($format === 'pdf') {
            return $this->employeeStatementPdf($statement);
        }

        $emp = $statement['employee'];
        $headings = ['التاريخ', 'المرجع', 'التصنيف', 'الوصف', 'النوع', 'المبلغ (ر.س)', 'الرصيد الجاري (ر.س)'];
        $rows = array_map(fn (array $m) => [
            $m['movementDate'] ? Carbon::parse($m['movementDate'])->toDateString() : '—',
            $m['ref'] ?? '—',
            $m['categoryLabelAr'] ?? ($m['category'] ?? '—'),
            $m['description'] ?? '—',
            $m['movementTypeLabelAr'] ?? $m['movementType'],
            $this->sar($m['amountHalalas']),
            $this->sar($m['runningBalanceHalalas']),
        ], $statement['movements']);

        // Closing-balance footer.
        $rows[] = ['', '', '', 'الرصيد الختامي', '', '', $this->sar($statement['closingBalanceHalalas'])];

        $label = 'statement-'.($emp['empNumber'] ?? $emp['id']).'-'.$statement['period']['month'];

        return $this->make($format, $label, $headings, $rows);
    }

    /** «تحميل PDF» on the statement screen — same rows, print-ready RTL sheet. */
    private function employeeStatementPdf(array $statement): Response
    {
        $emp = $statement['employee'];
        $period = $statement['period'];
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $body = '';
        foreach ($statement['movements'] as $m) {
            $body .= '<tr>'
                .'<td>'.$e($m['movementDate'] ? Carbon::parse($m['movementDate'])->toDateString() : '—').'</td>'
                .'<td>'.$e($m['ref'] ?? '—').'</td>'
                .'<td>'.$e($m['categoryLabelAr'] ?? ($m['category'] ?? '—')).'</td>'
                .'<td>'.$e($m['description'] ?? '—').'</td>'
                .'<td>'.$e($m['movementTypeLabelAr'] ?? $m['movementType']).'</td>'
                .'<td style="text-align:left">'.$this->sar($m['amountHalalas']).'</td>'
                .'<td style="text-align:left">'.$this->sar($m['runningBalanceHalalas']).'</td>'
                .'</tr>';
        }

        $html = '<html dir="rtl"><head><style>'
            .'body{font-family:dejavusans;font-size:11px;color:#222} h1{font-size:18px;margin:0 0 4px}'
            .'table{width:100%;border-collapse:collapse;margin-top:10px} th,td{border:1px solid #ddd;padding:5px;text-align:right}'
            .'th{background:#f3f4f6} .muted{color:#888} .tot{width:55%;margin-right:auto;margin-top:12px}'
            .'</style></head><body>'
            .'<h1>كشف حساب الموظف</h1>'
            .'<p class="muted">'.$e($emp['name'] ?? '—').' — رقم الموظف: '.$e($emp['empNumber'] ?? '—').'</p>'
            .'<p>الفترة: '.$e($period['from']).' → '.$e($period['to']).' ('.$e($period['month']).')</p>'
            .'<table><thead><tr><th>التاريخ</th><th>المرجع</th><th>التصنيف</th><th>الوصف</th><th>النوع</th>'
            .'<th>المبلغ (ر.س)</th><th>الرصيد الجاري (ر.س)</th></tr></thead><tbody>'
            .($body ?: '<tr><td colspan="7" class="muted">لا توجد حركات في هذه الفترة</td></tr>')
            .'</tbody></table>'
            .'<table class="tot">'
            .'<tr><th>الرصيد الافتتاحي</th><td style="text-align:left">'.$this->sar($statement['openingBalanceHalalas']).'</td></tr>'
            .'<tr><th>إجمالي الدائن</th><td style="text-align:left">'.$this->sar($statement['totalCredit'] ?? 0).'</td></tr>'
            .'<tr><th>إجمالي المدين</th><td style="text-align:left">'.$this->sar($statement['totalDebit'] ?? 0).'</td></tr>'
            .'<tr><th>الرصيد الختامي</th><td style="text-align:left">'.$this->sar($statement['closingBalanceHalalas']).'</td></tr>'
            .'</table>'
            .'</body></html>';

        return $this->renderPdf($html, 'statement-'.($emp['empNumber'] ?? $emp['id']).'-'.$period['month']);
    }

    /**
     * ACC-8 «ملخص عهد Excel». Honours the same filters as the list screen so the
     * sheet matches what the accountant is looking at.
     *
     * @param  array{brandId?:?string, status?:?string}  $filters
     */
    public function cashCustody(string $format, ?string $branchId, ?array $branchIds = null, array $filters = []): BinaryFileResponse
    {
        $q = CashCustody::query();
        // Zero-trust: a branch-scoped accountant exports only their branches.
        if ($branchIds !== null) {
            $q->whereIn('branch_id', $branchIds);
        }
        $this->brandBranches->applyFilter($q, $filters['brandId'] ?? null);
        $this->custody->applyStatusFilter($q, $filters['status'] ?? null);
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        $rowsM = $q->orderByDesc('created_at')->limit(5000)->get();
        $branchNames = $this->branchNames($rowsM->pluck('branch_id'));

        $headings = ['الفرع', 'أمين العهدة', 'العهدة (ر.س)', 'المصروف (ر.س)', 'المتبقي (ر.س)', 'حد التنبيه (ر.س)', 'أيام منذ التسوية', 'الحالة'];
        $rows = $rowsM->map(fn (CashCustody $c) => [
            $branchNames[$c->branch_id] ?? '—',
            $c->custodian_name ?? '—',
            $this->sar($c->amount),
            $this->sar($c->used),
            $this->sar((int) $c->amount - (int) $c->used),
            $this->sar($c->min_alert),
            (string) $c->days_since_settlement,
            \Modules\Admin\Support\CustodyStatus::labelAr(
                \Modules\Admin\Support\CustodyStatus::derive((int) $c->amount - (int) $c->used, $c->min_alert)
            ),
        ])->all();

        return $this->make($format, 'cash-custody', $headings, $rows);
    }

    /**
     * ACC-3 «تصدير Excel» on the purchases board: one row per invoice LINE, with
     * its card (supplier or branch) repeated so the sheet pivots cleanly.
     *
     * @param  array<int, array<string, mixed>>  $groups  cards from PurchaseBoardService
     */
    public function purchaseBoard(string $format, array $groups, string $groupBy = 'supplier'): BinaryFileResponse
    {
        $headings = [
            'المورد', 'الفرع', 'رقم الفاتورة', 'التاريخ', 'الحالة', 'المطابقة',
            'اسم الصنف', 'الوحدة', 'سعر الوحدة (ر.س)', 'آخر سعر وصول (ر.س)', 'الفرق (ر.س)',
            'الكمية', 'الإجمالي (ر.س)', 'موثّق',
        ];

        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['invoices'] ?? [] as $invoice) {
                $lines = $invoice['lines'] ?? [];
                if ($lines === []) {
                    // An invoice with no mapped lines still belongs on the sheet.
                    $rows[] = [
                        $invoice['supplierName'] ?? '—', $invoice['branchName'] ?? '—',
                        $invoice['invoiceNumber'] ?? '—', $invoice['date'] ?? '—',
                        $invoice['statusLabelAr'] ?? '—', $invoice['matchLabelAr'] ?? '—',
                        '—', '—', '', '', '', '', $this->sar($invoice['totalHalalas'] ?? 0),
                        ($invoice['isDocumented'] ?? false) ? 'نعم' : 'لا',
                    ];

                    continue;
                }
                foreach ($lines as $line) {
                    $rows[] = [
                        $invoice['supplierName'] ?? '—',
                        $invoice['branchName'] ?? '—',
                        $invoice['invoiceNumber'] ?? '—',
                        $invoice['date'] ?? '—',
                        $invoice['statusLabelAr'] ?? '—',
                        $invoice['matchLabelAr'] ?? '—',
                        $line['item'] ?? '—',
                        $line['unit'] ?? '—',
                        $this->sar($line['unitPriceHalalas'] ?? 0),
                        $line['lastArrivalPriceHalalas'] === null ? '—' : $this->sar($line['lastArrivalPriceHalalas']),
                        $line['priceDeltaHalalas'] === null ? '—' : $this->sar($line['priceDeltaHalalas']),
                        (string) ($line['qty'] ?? 0),
                        $this->sar($line['totalHalalas'] ?? 0),
                        ($line['documented'] ?? false) ? 'نعم' : 'لا',
                    ];
                }
            }
        }

        return $this->make($format, 'purchases-by-'.$groupBy, $headings, $rows);
    }

    // ── Operations / list exports (sync xlsx/csv; honour list filters) ──────

    /**
     * Operations export — sales/expenses/purchases (and a shared all-module
     * variant when moduleKey is null). Honours the same filters as the list.
     *
     * @param  array{moduleKey?:?string,status?:?string,branchId?:?string,brandId?:?string,dateFrom?:?string,dateTo?:?string,branchIds?:?array}  $filters
     */
    public function operations(string $format, string|array $companyId, array $filters): BinaryFileResponse
    {
        $module = $filters['moduleKey'] ?? null;
        $ops = $this->filteredOperations($companyId, $filters);
        $branchNames = $this->branchNames($ops->pluck('branch_id'));

        if ($module === 'purchases') {
            $supplierNames = $this->supplierNames($ops->map(fn (Operation $o) => $o->payload['supplierId'] ?? null));
            $headings = ['رقم الطلب', 'المورد', 'الفرع', 'الإجمالي (ر.س)', 'عدد الأصناف', 'حالة الاعتماد', 'حالة الإرسال'];
            $rows = $ops->map(function (Operation $o) use ($branchNames, $supplierNames) {
                $payload = $o->payload ?? [];
                $items = $payload['items'] ?? [];

                return [
                    $o->public_id,
                    $supplierNames[$payload['supplierId'] ?? ''] ?? '—',
                    $branchNames[$o->branch_id] ?? '—',
                    $this->sar($o->amount),
                    (string) (is_array($items) ? count($items) : 0),
                    $o->status,
                    ! empty($payload['sentAt']) ? 'مُرسل' : 'لم يُرسل',
                ];
            })->all();

            return $this->make($format, 'operations-purchases', $headings, $rows);
        }

        $isExpense = $module === 'expenses';
        $brandByBranch = $this->brandNamesByBranch($ops->pluck('branch_id'));
        $userNames = $this->userNames($ops->pluck('submitted_by_id')->merge($ops->pluck('reviewed_by_id')));

        $headings = ['رقم العملية', 'الفرع', 'العلامة التجارية', 'الوحدة', 'المبلغ (ر.س)', 'الحالة', 'المطابقة', 'مُقدّم من', 'التاريخ', 'روجع بواسطة', 'تاريخ المراجعة', 'ملاحظات'];
        if ($isExpense) {
            $headings = array_merge($headings, ['المورد', 'رقم الفاتورة', 'مُتحقَّق', 'حُوِّل لأصل']);
        }

        $rows = $ops->map(function (Operation $o) use ($branchNames, $brandByBranch, $userNames, $isExpense) {
            $payload = $o->payload ?? [];
            $row = [
                $o->public_id,
                $branchNames[$o->branch_id] ?? '—',
                $brandByBranch[$o->branch_id] ?? '—',
                $o->module_key,
                $this->sar($o->amount),
                $o->status,
                $o->match ?? '—',
                $userNames[$o->submitted_by_id] ?? '—',
                optional($o->operation_date)->toDateString() ?? optional($o->created_at)->toDateString(),
                $userNames[$o->reviewed_by_id] ?? '—',
                optional($o->reviewed_at)->toDateTimeString() ?? '',
                $o->diff_note ?? '',
            ];
            if ($isExpense) {
                $firstInv = is_array($payload['invoices'] ?? null) ? ($payload['invoices'][0] ?? []) : [];
                $row[] = $payload['vendor'] ?? ($firstInv['vendor'] ?? '—');
                $row[] = $payload['invNum'] ?? ($firstInv['invNum'] ?? '—');
                $row[] = ! empty($payload['verified']) ? 'نعم' : 'لا';
                $row[] = ! empty($payload['convertedToAsset']) ? 'نعم' : 'لا';
            }

            return $row;
        })->all();

        return $this->make($format, 'operations'.($module ? '-'.$module : ''), $headings, $rows);
    }

    /**
     * Tenant-scoped operations matching the list filters. brandId is resolved
     * post-load via the branch→brand map (operations carry no brand column).
     *
     * `branchIds` is the caller's assigned-branch scope (null = company-wide):
     * a branch-scoped accountant must never export outside their own branches.
     *
     * @param  array<string,mixed>  $filters
     */
    private function filteredOperations(string $companyId, array $filters): \Illuminate\Support\Collection
    {
        $q = Operation::whereIn('company_id', (array) $companyId);
        if (($filters['branchIds'] ?? null) !== null) {
            $q->whereIn('branch_id', $filters['branchIds']);
        }
        if (! empty($filters['moduleKey'])) {
            $q->where('module_key', $filters['moduleKey']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['branchId'])) {
            $q->where('branch_id', $filters['branchId']);
        }
        if (! empty($filters['dateFrom'])) {
            $q->whereDate('operation_date', '>=', $filters['dateFrom']);
        }
        if (! empty($filters['dateTo'])) {
            $q->whereDate('operation_date', '<=', $filters['dateTo']);
        }
        $ops = $q->orderByDesc('operation_date')->limit(10000)->get();

        if (! empty($filters['brandId'])) {
            $branchToBrand = $this->branchToBrandId($ops->pluck('branch_id'));
            $ops = $ops->filter(fn (Operation $o) => ($branchToBrand[$o->branch_id] ?? null) === $filters['brandId'])->values();
        }

        return $ops;
    }

    /**
     * Fixed-assets register export.
     *
     * `$branchIds` is the caller's assigned-branch scope (null = company-wide);
     * without it a branch-restricted accountant exported the whole register.
     *
     * @param  string[]|null  $branchIds
     */
    public function assets(string $format, string|array $companyId, ?string $category, ?string $branchId, ?array $branchIds = null): BinaryFileResponse
    {
        $q = Asset::whereIn('company_id', (array) $companyId);
        if ($branchIds !== null) {
            $q->whereIn('branch_id', $branchIds);
        }
        if ($category) {
            $q->where('category', $category);
        }
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        $assets = $q->orderBy('public_id')->limit(10000)->get();
        $branchNames = $this->branchNames($assets->pluck('branch_id'));

        $headings = ['رمز الأصل', 'الاسم', 'التصنيف', 'الرقم التسلسلي', 'الفرع', 'تاريخ الشراء', 'سعر الشراء (ر.س)', 'العمر الإنتاجي (شهر)', 'الإهلاك الشهري (ر.س)', 'الإهلاك السنوي (ر.س)', 'القيمة الدفترية (ر.س)', 'العهدة', 'الحالة'];
        $rows = $assets->map(fn (Asset $a) => [
            $a->public_id,
            $a->name,
            AssetEnums::categoryLabelAr($a->category) ?? '—',
            $a->serial ?? '—',
            $branchNames[$a->branch_id] ?? '—',
            optional($a->purchased_at)->toDateString() ?? '',
            $this->sar($a->cost),
            (string) $a->useful_life_months,
            $this->sar(AssetEnums::monthlyDepreciation((int) $a->cost, $a->useful_life_months)),
            $this->sar(AssetEnums::annualDepreciation((int) $a->cost, $a->useful_life_months)),
            $this->sar($a->book_value),
            $a->custodian ?? '—',
            AssetEnums::statusLabelAr($a->status) ?? '—',
        ])->all();

        return $this->make($format, 'fixed-assets', $headings, $rows);
    }

    /** Accountant reminders export. */
    public function reminders(string $format, string $companyId): BinaryFileResponse
    {
        $reminders = Reminder::where('company_id', $companyId)->orderByDesc('created_at')->limit(10000)->get();
        $branchNames = $this->branchNames($reminders->pluck('branch_id'));

        $headings = ['الفرع', 'العنصر الناقص', 'أيام التأخير', 'آخر تذكير مُرسل', 'الرد', 'نوع التذكير'];
        $rows = $reminders->map(fn (Reminder $r) => [
            $branchNames[$r->branch_id] ?? '—',
            $r->report_type ?? ($r->module_key ?? '—'),
            (string) ($r->days_missing ?? 0),
            optional($r->sent_at)->toDateTimeString() ?? '—',
            $r->response ?? ($r->reminder_status ?? '—'),
            $r->module_key ?? '—',
        ])->all();

        return $this->make($format, 'reminders', $headings, $rows);
    }

    /** Company suppliers export (procurement portal). */
    public function companySuppliers(string $format, string $companyId): BinaryFileResponse
    {
        $suppliers = AsabSupplier::where('company_id', $companyId)->orderBy('name')->limit(10000)->get();
        $orderCounts = Operation::where('company_id', $companyId)->where('module_key', 'purchases')
            ->limit(20000)->get(['payload'])
            ->groupBy(fn (Operation $o) => $o->payload['supplierId'] ?? null)->map->count();

        $headings = ['اسم المورد', 'التصنيف', 'الجوال', 'البريد', 'التقييم', 'عدد الطلبات', 'نشط'];
        $rows = $suppliers->map(fn (AsabSupplier $s) => [
            $s->name,
            $s->category ?? '—',
            $s->contact_phone ?? '—',
            $s->contact_email ?? '—',
            number_format(((int) $s->rating) / 10, 1, '.', ''),
            (string) ($orderCounts[$s->id] ?? 0),
            $s->status === 'active' ? 'نعم' : 'لا',
        ])->all();

        return $this->make($format, 'suppliers', $headings, $rows);
    }

    /** Company procurement catalog export. */
    public function procurementItems(string $format, string $companyId): BinaryFileResponse
    {
        $items = SupplierItem::where('company_id', $companyId)->orderBy('name')->limit(10000)->get();

        $headings = ['رمز الصنف', 'الاسم', 'الوحدة', 'آخر سعر (ر.س)', 'نشط'];
        $rows = $items->map(fn (SupplierItem $i) => [
            $i->code ?? '—',
            $i->name,
            $i->unit ?? '—',
            $this->sar($i->price),
            $i->status === 'active' ? 'نعم' : 'لا',
        ])->all();

        return $this->make($format, 'procurement-items', $headings, $rows);
    }

    /** Supplier-portal catalog export (scoped to the supplier user). */
    public function supplierItems(string $format, string $userId): BinaryFileResponse
    {
        $items = SupplierItem::where('supplier_user_id', $userId)->orderBy('name')->limit(10000)->get();

        $headings = ['رمز الصنف', 'الاسم', 'الوحدة', 'السعر (ر.س)', 'أقل كمية', 'نشط'];
        $rows = $items->map(fn (SupplierItem $i) => [
            $i->code ?? '—',
            $i->name,
            $i->unit ?? '—',
            $this->sar($i->price),
            (string) ($i->min_qty ?? ''),
            $i->status === 'active' ? 'نعم' : 'لا',
        ])->all();

        return $this->make($format, 'supplier-items', $headings, $rows);
    }

    /**
     * Supplier-portal orders export (accepted/rejected purchase operations).
     *
     * @param  string[]|null  $supplierIds  restrict to these asab_suppliers ids (null = unrestricted)
     */
    public function supplierOrders(string $format, ?string $status, ?array $supplierIds = null): BinaryFileResponse
    {
        $q = Operation::where('module_key', 'purchases');
        if ($supplierIds !== null) {
            $q->whereIn('payload->supplierId', $supplierIds);
        }
        // Same canonical vocabulary as the JSON list (SupplierOrderStatus): the
        // `accepted` filter folds accepted/confirmed/approved/final-approved so
        // the export never diverges from the on-screen separate lists.
        if ($synonyms = \Modules\Admin\Support\SupplierOrderStatus::synonyms($status)) {
            $q->whereIn('status', $synonyms);
        }
        $ops = $q->orderByDesc('operation_date')->limit(10000)->get();
        $branchNames = $this->branchNames($ops->pluck('branch_id'));

        $headings = ['رقم الطلب', 'الفرع', 'الإجمالي (ر.س)', 'الحالة', 'التاريخ'];
        $rows = $ops->map(fn (Operation $o) => [
            $o->public_id,
            $branchNames[$o->branch_id] ?? '—',
            $this->sar($o->amount),
            $o->status,
            optional($o->operation_date)->toDateString() ?? '',
        ])->all();

        return $this->make($format, 'supplier-orders', $headings, $rows);
    }

    /**
     * Platform audit-log export.
     *
     * @param  array{action?:?string,actorUserId?:?string,userFilter?:?string,dateFrom?:?string,dateTo?:?string}  $filters
     */
    public function auditLogs(string $format, array $filters): BinaryFileResponse
    {
        $q = AuditLog::query();
        if (! empty($filters['action'])) {
            $q->where('action', $filters['action']);
        }
        if (! empty($filters['actorUserId'])) {
            $q->where('actor_user_id', $filters['actorUserId']);
        }
        if (! empty($filters['userFilter'])) {
            $q->where('actor_label', 'like', '%'.$filters['userFilter'].'%');
        }
        if (! empty($filters['dateFrom'])) {
            $q->where('occurred_at', '>=', $filters['dateFrom']);
        }
        if (! empty($filters['dateTo'])) {
            $q->where('occurred_at', '<=', $filters['dateTo']);
        }
        $logs = $q->orderByDesc('occurred_at')->limit(10000)->get();

        $headings = ['التاريخ', 'الوقت', 'المستخدم', 'الإجراء', 'النوع', 'العنصر المستهدف', 'عنوان IP', 'ملاحظات'];
        $rows = $logs->map(fn (AuditLog $l) => [
            optional($l->occurred_at)->toDateString() ?? '',
            optional($l->occurred_at)->format('H:i:s') ?? '',
            $l->actor_label ?? '—',
            $l->action ?? '—',
            $l->entity_type ?? '—',
            $l->entity_id ?? '—',
            $l->ip ?? '—',
            $l->description ?? '',
        ])->all();

        return $this->make($format, 'audit-logs', $headings, $rows);
    }

    // ── Billing invoices export (async job target) ──────────────────────────

    /**
     * All invoices for a company as an xlsx download. Called by the queued
     * GenerateCompanyExportJob (the HTTP endpoint returns 202 + jobId).
     */
    public function billingInvoicesToFile(string $companyId, ?string $from, ?string $to, string $format): string
    {
        $q = BillingInvoice::withoutGlobalScopes()->where('company_id', $companyId);
        if ($from) {
            $q->where('issue_date', '>=', $from);
        }
        if ($to) {
            $q->where('issue_date', '<=', $to);
        }
        $invoices = $q->orderByDesc('issue_date')->limit(5000)->get();

        $headings = ['رقم الفاتورة', 'تاريخ الإصدار', 'تاريخ الاستحقاق', 'الإجمالي (ر.س)', 'المدفوع (ر.س)', 'المتبقي (ر.س)', 'الحالة'];
        $rows = $invoices->map(fn (BillingInvoice $i) => [
            $i->public_id,
            optional($i->issue_date)->toDateString(),
            optional($i->due_date)->toDateString(),
            $this->sar($i->total),
            $this->sar($i->amount_paid),
            $this->sar($i->amount_due),
            $i->status,
        ])->all();

        return $this->writeToPath($format, $headings, $rows);
    }

    /** Write a sheet to a stable storage path and return that path. */
    private function writeToPath(string $format, array $headings, array $rows): string
    {
        $format = in_array($format, ['xlsx', 'csv'], true) ? $format : 'xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'asab_export_');
        $writer = $format === 'csv' ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues($headings));
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues(array_values($r)));
        }
        $writer->close();

        return $tmp;
    }

    // ── PDF (invoices, reports) ─────────────────────────────────────────────

    /** Render an Arabic-shaped, RTL PDF and return it as an attachment response. */
    private function renderPdf(string $html, string $filename): Response
    {
        $tmpDir = storage_path('app/mpdf-tmp');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tmpDir,
            'autoScriptToLang' => true,
            'autoArabic' => true,
            'autoLangToFont' => true,
            'default_font' => 'dejavusans',
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
        ]);
    }

    public function invoicePdf(BillingInvoice $inv): Response
    {
        $inv->loadMissing('lines');
        $addr = $inv->billing_address_snapshot ?? [];
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $lineRows = '';
        foreach ($inv->lines as $l) {
            $lineRows .= '<tr><td>'.$e($l->description).'</td><td style="text-align:center">'.$e($l->quantity)
                .'</td><td style="text-align:left">'.$this->sar($l->unit_price).'</td><td style="text-align:left">'.$this->sar($l->amount).'</td></tr>';
        }

        $html = '<html dir="rtl"><head><style>'
            .'body{font-family:dejavusans;font-size:12px;color:#222} h1{font-size:20px;margin:0}'
            .'table{width:100%;border-collapse:collapse;margin-top:12px} th,td{border:1px solid #ddd;padding:6px;text-align:right}'
            .'th{background:#f3f4f6} .tot{width:50%;margin-right:auto;margin-top:14px} .muted{color:#888}'
            .'</style></head><body>'
            .'<h1>فاتورة عصب</h1>'
            .'<p class="muted">رقم: '.$e($inv->public_id).' — الحالة: '.$e($inv->status).'</p>'
            .'<p>تاريخ الإصدار: '.$e(optional($inv->issue_date)->toDateString()).' — الاستحقاق: '.$e(optional($inv->due_date)->toDateString()).'</p>'
            .'<p><strong>إلى:</strong> '.$e($addr['legalName'] ?? ($addr['legal_name'] ?? '—'))
            .($addr['taxId'] ?? $addr['tax_id'] ?? null ? ' — الرقم الضريبي: '.$e($addr['taxId'] ?? $addr['tax_id']) : '').'</p>'
            .'<table><thead><tr><th>الوصف</th><th>الكمية</th><th>سعر الوحدة (ر.س)</th><th>الإجمالي (ر.س)</th></tr></thead><tbody>'
            .($lineRows ?: '<tr><td colspan="4" class="muted">لا توجد بنود</td></tr>')
            .'</tbody></table>'
            .'<table class="tot"><tr><th>المجموع الفرعي</th><td style="text-align:left">'.$this->sar($inv->subtotal).'</td></tr>'
            .'<tr><th>ضريبة القيمة المضافة</th><td style="text-align:left">'.$this->sar($inv->vat_amount).'</td></tr>'
            .'<tr><th>الخصم</th><td style="text-align:left">'.$this->sar($inv->discount).'</td></tr>'
            .'<tr><th>الإجمالي</th><td style="text-align:left">'.$this->sar($inv->total).'</td></tr>'
            .'<tr><th>المدفوع</th><td style="text-align:left">'.$this->sar($inv->amount_paid).'</td></tr>'
            .'<tr><th>المتبقي</th><td style="text-align:left">'.$this->sar($inv->amount_due).'</td></tr></table>'
            .'</body></html>';

        return $this->renderPdf($html, 'invoice-'.$inv->public_id);
    }

    /** Export a built report payload (from ReportService) as pdf or xlsx. */
    /**
     * Render a report to raw bytes for a mail attachment (T15.3 owner dispatch).
     *
     * @return array{filename:string, contents:string, mime:string}
     */
    public function reportBytes(array $report, string $format): array
    {
        $key = $report['reportKey'] ?? 'report';

        if ($format === 'pdf') {
            return [
                'filename' => 'report-'.$key.'.pdf',
                'contents' => $this->report($report, 'pdf')->getContent(),
                'mime' => 'application/pdf',
            ];
        }

        $sections = $this->flattenReport($report);
        $rows = [];
        foreach ($sections as $sec) {
            foreach ($sec['rows'] as $r) {
                $rows[] = array_merge([$sec['title']], array_pad(array_values($r), 2, ''));
            }
        }
        $path = $this->writeToPath('xlsx', ['القسم', 'الحقل', 'القيمة'], $rows);
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return [
            'filename' => 'report-'.$key.'.xlsx',
            'contents' => $contents,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    public function report(array $report, string $format): Response
    {
        $sections = $this->flattenReport($report);
        $title = 'تقرير: '.($report['reportKey'] ?? '');
        $period = ($report['period']['from'] ?? '—').' → '.($report['period']['to'] ?? '—');

        if ($format === 'pdf') {
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $body = '';
            foreach ($sections as $sec) {
                $body .= '<h3>'.$e($sec['title']).'</h3><table><thead><tr>';
                foreach ($sec['headings'] as $h) {
                    $body .= '<th>'.$e($h).'</th>';
                }
                $body .= '</tr></thead><tbody>';
                foreach ($sec['rows'] as $row) {
                    $body .= '<tr>';
                    foreach ($row as $cell) {
                        $body .= '<td>'.$e($cell).'</td>';
                    }
                    $body .= '</tr>';
                }
                $body .= '</tbody></table>';
            }
            $html = '<html dir="rtl"><head><style>body{font-family:dejavusans;font-size:12px}'
                .'h1{font-size:18px} h3{margin:14px 0 4px} table{width:100%;border-collapse:collapse;margin-bottom:8px}'
                .'th,td{border:1px solid #ddd;padding:5px;text-align:right} th{background:#f3f4f6}</style></head><body>'
                .'<h1>'.$e($title).'</h1><p>'.$e($period).'</p>'.$body.'</body></html>';

            return $this->renderPdf($html, 'report-'.($report['reportKey'] ?? 'report'));
        }

        // xlsx: stack each section (heading row, table, blank separator) into one sheet.
        $headings = ['القسم', 'الحقل', 'القيمة'];
        $rows = [];
        foreach ($sections as $sec) {
            foreach ($sec['rows'] as $r) {
                $rows[] = array_merge([$sec['title']], array_pad(array_values($r), 2, ''));
            }
        }

        return $this->make('xlsx', 'report-'.($report['reportKey'] ?? 'report'), $headings, $rows);
    }

    /**
     * Normalise a report payload into printable sections. Scalars collapse into
     * a key/value summary; lists of objects become their own labelled tables.
     *
     * @return array<int, array{title:string, headings:array, rows:array}>
     */
    private function flattenReport(array $report): array
    {
        $data = $report['data'] ?? $report;
        $summary = [];
        $tables = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && array_is_list($value) && isset($value[0]) && is_array($value[0])) {
                $headings = array_keys($value[0]);
                $rows = array_map(fn ($item) => array_map(fn ($c) => is_scalar($c) ? $c : json_encode($c, JSON_UNESCAPED_UNICODE), array_values($item)), $value);
                $tables[] = ['title' => (string) $key, 'headings' => $headings, 'rows' => $rows];
            } elseif (is_array($value)) {
                foreach ($value as $k2 => $v2) {
                    $summary[] = [$key.'.'.$k2, is_scalar($v2) ? (string) $v2 : json_encode($v2, JSON_UNESCAPED_UNICODE)];
                }
            } else {
                $summary[] = [(string) $key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
            }
        }

        $sections = [];
        if ($summary !== []) {
            $sections[] = ['title' => 'ملخص', 'headings' => ['الحقل', 'القيمة'], 'rows' => $summary];
        }

        return array_merge($sections, $tables);
    }
}
