<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;

/**
 * SRS ACC-2.1 — the expenses screen's KPI strip. The headline number the
 * accountant acts on is not "how many statements are pending" but how those
 * pending statements' invoices break down: مطابقة / غير مطابقة / مفقودة.
 */
class ExpenseKpiService
{
    public function __construct(private readonly ExpenseInvoiceService $invoices) {}

    /**
     * @param  string[]|null  $branchIds  assigned-branch scope (null = company-wide)
     * @return array<string, mixed>
     */
    public function forRange(string $companyId, ?array $branchIds, ?string $from, ?string $to): array
    {
        $from ??= now()->toDateString();
        $to ??= $from;

        // `Operation::query()` keeps the SoftDeletes + tenant global scopes; the
        // explicit company filter pins a platform admin to the requested tenant.
        $ops = Operation::query()
            ->where('company_id', $companyId)
            ->where('module_key', 'expenses')
            ->whereDate('operation_date', '>=', $from)
            ->whereDate('operation_date', '<=', $to)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->limit(5000)
            ->get();

        // One query for every statement's documents — the match badge depends on
        // them and a per-operation lookup would be an N+1 across the whole month.
        $attachments = Attachment::whereIn('owner_id', $ops->pluck('id'))
            ->orderBy('uploaded_at')->get()->groupBy('owner_id');

        $totals = ['preTaxHalalas' => 0, 'vat15Halalas' => 0, 'inclTaxHalalas' => 0];
        $pending = ['total' => 0, 'matched' => 0, 'mismatch' => 0, 'missing' => 0];
        $invoiceCount = 0;
        $verified = 0;
        $converted = 0;

        foreach ($ops as $op) {
            $block = $this->invoices->present(
                $op, $this->invoices->attachmentRows($op, $attachments->get($op->id, collect())),
            );
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $block['totals'][$key];
            }
            $invoiceCount += $block['totals']['invoiceCount'];
            $verified += $block['verifiedCount'];

            foreach ($block['invoices'] as $invoice) {
                $converted += $invoice['convertedToAsset'] ? 1 : 0;
                if ($op->status === Operation::STATUS_PENDING) {
                    $pending['total']++;
                    $pending[$invoice['matchStatus']]++;
                }
            }
        }

        return [
            'dateFrom' => $from,
            'dateTo' => $to,
            'statementCount' => $ops->count(),
            'invoiceCount' => $invoiceCount,
            'totalHalalas' => $totals['inclTaxHalalas'],
            'preTaxHalalas' => $totals['preTaxHalalas'],
            'vat15Halalas' => $totals['vat15Halalas'],
            'pendingStatementCount' => $ops->where('status', Operation::STATUS_PENDING)->count(),
            'pendingInvoices' => $pending,
            'verifiedInvoiceCount' => $verified,
            'unverifiedInvoiceCount' => $invoiceCount - $verified,
            'convertedInvoiceCount' => $converted,
        ];
    }
}
