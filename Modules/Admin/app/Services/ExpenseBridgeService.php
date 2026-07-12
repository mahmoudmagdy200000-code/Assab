<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseAttachment;
use Modules\Expense\Models\InvoiceDetail;

/**
 * Two-worlds bridge: a mobile-app expense (`Modules\Expense`) becomes an ASAB
 * `asab_operations` row so the dashboard accountant can review it.
 *
 * Legacy invoices carry their own net/VAT lines, which are mapped straight
 * across rather than re-derived — a zero-rated invoice must not sprout 15% VAT
 * on the way into the dashboard.
 *
 * Idempotent: keyed on (`source_module`, `source_id`). Once the accountant has
 * acted on the ASAB side, the mobile record no longer overwrites it.
 */
class ExpenseBridgeService
{
    public function __construct(
        private readonly ExpenseInvoiceService $invoices,
        private readonly RealtimeBroadcaster $rt,
    ) {}

    public const SOURCE = 'expense';

    /** @return Operation|null null when the expense belongs to no ASAB company */
    public function sync(Expense $expense): ?Operation
    {
        $branchId = $expense->branchManager?->branch_id;
        if ($branchId === null) {
            return null;
        }

        $branch = Branch::whereKey($branchId)->first(['id', 'asab_company_id']);
        $companyId = $branch?->asab_company_id;
        if ($companyId === null) {
            return null;   // legacy-only branch — nothing to mirror into.
        }

        // The mobile submitter is not an ASAB user, so no tenant context exists
        // to scope by — drop the global scopes and pin the company explicitly.
        $existing = Operation::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('source_module', self::SOURCE)
            ->where('source_id', $expense->id)
            ->first();

        // The accountant owns the record once they touch it.
        if ($existing && $existing->status !== Operation::STATUS_PENDING) {
            return $existing;
        }

        $invoices = $this->mapInvoices($expense);
        $amount = $this->invoices->statementTotal($invoices)
            ?: (int) round(((float) $expense->total_amount) * 100);

        $payload = [
            'invoices' => $invoices,
            'attachments' => $this->statementAttachments($expense),
            'legacyStatus' => $expense->status,
            'expenseType' => $expense->expense_type,
            'paymentMethod' => $expense->payment_method,
        ];

        if ($existing) {
            $existing->update(['payload' => $payload, 'amount' => $amount]);

            return $existing;
        }

        $op = OperationSequence::createWithPublicId(
            'EXP',
            fn (string $publicId) => DB::transaction(function () use ($publicId, $expense, $companyId, $branchId, $payload, $amount) {
                $op = Operation::create([
                    'public_id' => $publicId,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'module_key' => 'expenses',
                    'source_module' => self::SOURCE,
                    'source_id' => $expense->id,
                    'payload' => $payload,
                    'amount' => $amount,
                    'match' => 'exact',
                    'origin' => 'mobile',
                    'status' => Operation::STATUS_PENDING,
                    'submitted_at' => $expense->submitted_at ?? now(),
                    'operation_date' => $expense->submitted_at ?? now(),
                ]);

                ApprovalStep::create([
                    'operation_id' => $op->id,
                    'stage_id' => 'submit',
                    'action' => 'أُنشئ السجل من تطبيق الفرع: '.$op->public_id,
                    'actor_label' => $expense->branchManager?->name ?? 'تطبيق الفرع',
                    'occurred_at' => now(),
                ]);

                return $op;
            }),
        );

        $this->rt->operationCreated($op);

        return $op;
    }

    /**
     * `invoice_details` → the ACC-2 invoice rows. Amounts are decimal SAR in the
     * legacy schema and integer halalas in ASAB.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapInvoices(Expense $expense): array
    {
        $details = InvoiceDetail::where('expense_id', $expense->id)->orderBy('created_at')->get();
        if ($details->isEmpty()) {
            return [];
        }

        $attachments = ExpenseAttachment::where('expense_id', $expense->id)
            ->get()->groupBy('invoice_detail_id');

        return $details->values()->map(fn (InvoiceDetail $d) => array_filter([
            'invNum' => $d->invoice_number,
            'vendor' => $d->tax_supplier_name,
            'desc' => $expense->expense_type,
            'date' => optional($d->issue_date)->toDateString(),
            'amountHalalas' => $this->halalas($d->tax_total_amount),
            'vatHalalas' => $d->tax_vat_amount === null ? null : $this->halalas($d->tax_vat_amount),
            'attachments' => $this->files($attachments->get($d->id, collect())),
        ], fn ($v) => $v !== null))->all();
    }

    /** Documents attached to the expense itself rather than to one invoice. */
    private function statementAttachments(Expense $expense): array
    {
        return $this->files(
            ExpenseAttachment::where('expense_id', $expense->id)->whereNull('invoice_detail_id')->get(),
        );
    }

    /** @param  \Illuminate\Support\Collection<int, ExpenseAttachment>  $rows */
    private function files(\Illuminate\Support\Collection $rows): array
    {
        return $rows->map(fn (ExpenseAttachment $a) => [
            'id' => $a->id,
            'filename' => $a->file_name,
            'mimeType' => $a->file_type,
            'size' => $a->file_size,
            'publicUrl' => $a->file_path,
        ])->values()->all();
    }

    private function halalas(mixed $sar): int
    {
        return (int) round(((float) $sar) * 100);
    }
}
