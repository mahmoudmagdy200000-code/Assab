<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
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

    public function operation(string $format, string $opId): BinaryFileResponse
    {
        $op = Operation::where(fn ($q) => $q->where('id', $opId)->orWhere('public_id', $opId))->firstOrFail();
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

        // Append per-module line items from the payload so the sheet carries detail, not just the envelope.
        $payload = $op->payload ?? [];
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

    public function waste(string $format, ?string $branchId): BinaryFileResponse
    {
        $q = Operation::where('module_key', 'waste');
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

    public function shifts(string $format, ?string $branchId): BinaryFileResponse
    {
        $q = Shift::where('status', 'closed');
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        $shifts = $q->orderByDesc('ended_at')->limit(5000)->get();
        $branchNames = $this->branchNames($shifts->pluck('branch_id'));

        $headings = ['الفرع', 'المشرف', 'البداية', 'النهاية', 'عدد الطلبات', 'المبيعات (ر.س)', 'النقد المتوقع', 'النقد الفعلي', 'الفرق'];
        $rows = $shifts->map(fn (Shift $s) => [
            $branchNames[$s->branch_id] ?? '—',
            $s->supervisor_name ?? '—',
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

    public function payroll(string $format, ?string $month): BinaryFileResponse
    {
        $month = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $employees = Employee::orderBy('emp_number')->limit(10000)->get();
        $branchNames = $this->branchNames($employees->pluck('branch_id'));

        // One aggregate query for credits/debits in the month, grouped by employee.
        $movements = EmployeeMovement::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('movement_date', [$start, $end])->get()->groupBy('employee_id');

        $headings = ['رقم الموظف', 'الاسم', 'الفرع', 'الوظيفة', 'الراتب (ر.س)', 'السلف (ر.س)', 'الخصومات (ر.س)', 'الصافي (ر.س)', 'الحالة'];
        $rows = $employees->map(function (Employee $e) use ($branchNames, $movements) {
            $mv = $movements->get($e->id, collect());
            $advances = (int) $mv->where('movement_type', 'debit')->sum('amount');
            $deductions = (int) $mv->where('movement_type', 'credit')->sum('amount');
            $net = (int) $e->monthly_salary - $advances - $deductions;

            return [
                $e->emp_number,
                $e->name,
                $branchNames[$e->branch_id] ?? '—',
                $e->role ?? '—',
                $this->sar($e->monthly_salary),
                $this->sar($advances),
                $this->sar($deductions),
                $this->sar($net),
                $e->status === 'active' ? 'نشط' : 'موقوف',
            ];
        })->all();

        return $this->make($format, 'payroll-'.$month, $headings, $rows);
    }

    public function cashCustody(string $format, ?string $branchId): BinaryFileResponse
    {
        $q = CashCustody::query();
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        $rowsM = $q->orderByDesc('created_at')->limit(5000)->get();
        $branchNames = $this->branchNames($rowsM->pluck('branch_id'));

        $headings = ['الفرع', 'أمين العهدة', 'العهدة (ر.س)', 'المصروف (ر.س)', 'المتبقي (ر.س)', 'أيام منذ التسوية', 'الحالة'];
        $rows = $rowsM->map(fn (CashCustody $c) => [
            $branchNames[$c->branch_id] ?? '—',
            $c->custodian_name ?? '—',
            $this->sar($c->amount),
            $this->sar($c->used),
            $this->sar((int) $c->amount - (int) $c->used),
            (string) $c->days_since_settlement,
            $c->status,
        ])->all();

        return $this->make($format, 'cash-custody', $headings, $rows);
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
