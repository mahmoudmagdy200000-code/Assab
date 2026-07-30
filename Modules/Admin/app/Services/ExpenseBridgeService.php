<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\Attachment;
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
        private readonly BranchHierarchyLinker $branches,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    public const SOURCE = 'expense';

    /** @return Operation|null null when the expense belongs to no ASAB company */
    public function sync(Expense $expense): ?Operation
    {
        // Every skip below is LOUD (meeting 2026-07-29: invoices "sent but never
        // arrived" were these silent returns) — the log line names the record
        // and the reason so ops can fix the link and run asab:bridge-backfill.
        $branchId = $expense->branchManager?->branch_id;
        if ($branchId === null) {
            $this->log->warning('expense-bridge: skipped — submitter has no branch', [
                'expense_id' => $expense->id, 'reason' => 'BRANCH_MISSING',
            ]);

            return null;
        }

        $branch = Branch::whereKey($branchId)->first(['id', 'asab_company_id', 'asab_brand_id', 'asab_restaurant_id']);
        if ($branch === null) {
            $this->log->warning('expense-bridge: skipped — branch row gone', [
                'expense_id' => $expense->id, 'branch_id' => $branchId, 'reason' => 'BRANCH_GONE',
            ]);

            return null;
        }
        // Heal the branch's hierarchy tags so the mirrored op resolves for a
        // brand/restaurant-scoped accountant, not just the head (scope=all).
        $this->branches->ensure($branch);

        $companyId = $branch->asab_company_id;
        if ($companyId === null) {
            $this->log->warning('expense-bridge: skipped — branch not linked to an ASAB company', [
                'expense_id' => $expense->id, 'branch_id' => $branchId, 'reason' => 'BRANCH_UNLINKED',
                'fix' => 'PATCH /api/v1/admin/branches/{id} with restaurantId, then php artisan asab:bridge-backfill',
            ]);

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
            // Meeting 2026-07-29: the accountant saw ops with no supplier and a
            // bare UID — carry the mobile-side identity so the dashboard can
            // label the record instead of showing an unlinked row.
            'supplierId' => $expense->supplier_id,
            'supplierName' => $expense->supplier?->name,
            'legacyExpenseId' => $expense->id,
            'submittedBy' => $expense->branchManager?->name,
        ];

        // The op date is the day the expense was actually submitted/issued —
        // never the bridge-run clock (a backfilled day-23 invoice must land on
        // day 23, not float to the top of the list on the backfill day).
        $date = $expense->submitted_at
            ?? InvoiceDetail::where('expense_id', $expense->id)->orderBy('created_at')->value('issue_date')
            ?? $expense->created_at;

        if ($existing) {
            $existing->update(['payload' => $payload, 'amount' => $amount, 'operation_date' => $date]);
            $this->syncAttachments($existing, $expense);

            return $existing;
        }

        $op = OperationSequence::createWithPublicId(
            'EXP',
            fn (string $publicId) => DB::transaction(function () use ($publicId, $expense, $companyId, $branchId, $payload, $amount, $date) {
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
                    'submitted_at' => $date,
                    'operation_date' => $date,
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

        $this->syncAttachments($op, $expense);
        $this->rt->operationCreated($op);

        return $op;
    }

    /**
     * Mirror the mobile expense's image/PDF attachments into `asab_attachments`
     * so GET /operations/{id}/attachments actually returns them (they only
     * lived inside `payload` before — the dashboard viewer saw nothing).
     * Idempotent on (owner_id, storage_key); never blocks the bridge.
     */
    private function syncAttachments(Operation $op, Expense $expense): void
    {
        try {
            $rows = ExpenseAttachment::where('expense_id', $expense->id)->get();

            // invoice_detail_id → positional index matching mapInvoices()'
            // created_at ordering and the 'invoice:i' label convention.
            $indexOf = array_flip(
                InvoiceDetail::where('expense_id', $expense->id)->orderBy('created_at')->pluck('id')->all()
            );

            foreach ($rows as $a) {
                Attachment::updateOrCreate(
                    ['owner_id' => $op->id, 'storage_key' => $a->file_path],
                    [
                        'owner_type' => 'operation',
                        'filename' => $a->file_name,
                        'mime_type' => $this->mime($a->file_type),
                        'size' => (int) $a->file_size,
                        'public_url' => Storage::disk('public')->url($a->file_path),
                        'label' => $a->invoice_detail_id !== null && isset($indexOf[$a->invoice_detail_id])
                            ? 'invoice:'.$indexOf[$a->invoice_detail_id]
                            : 'expense',
                        'uploaded_at' => $a->created_at,
                    ],
                );
            }

            $op->update(['attachment_count' => Attachment::where('owner_id', $op->id)->count()]);
        } catch (\Throwable $e) {
            $this->log->warning('expense-bridge: attachment mirror failed — operation minted without documents', [
                'expense_id' => $expense->id, 'operation_id' => $op->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * `invoice_details` → the ACC-2 invoice rows. Amounts are decimal SAR in the
     * legacy schema and integer halalas in ASAB.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapInvoices(Expense $expense): array
    {
        $details = InvoiceDetail::with('supplier:id,name')
            ->where('expense_id', $expense->id)->orderBy('created_at')->get();
        if ($details->isEmpty()) {
            return [];
        }

        $attachments = ExpenseAttachment::where('expense_id', $expense->id)
            ->get()->groupBy('invoice_detail_id');

        // Vendor fallback chain (meeting 2026-07-29 «بيانات ناقصة/غلط»): the
        // free-text tax name is only present on tax invoices, so fall back to
        // the invoice's picked supplier, then the expense-level supplier.
        return $details->values()->map(fn (InvoiceDetail $d) => array_filter([
            'invNum' => $d->invoice_number,
            'vendor' => $d->tax_supplier_name ?? $d->supplier?->name ?? $expense->supplier?->name,
            'supplierId' => $d->supplier_id ?? $expense->supplier_id,
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
            'mimeType' => $this->mime($a->file_type),
            'size' => $a->file_size,
            'storageKey' => $a->file_path,
            // A real absolute URL — the raw storage key rendered as a broken
            // image on the dashboard.
            'publicUrl' => Storage::disk('public')->url($a->file_path),
        ])->values()->all();
    }

    /** Legacy `file_type` stores the EXTENSION, not a MIME type. */
    private function mime(?string $ext): string
    {
        return match (strtolower((string) $ext)) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    private function halalas(mixed $sar): int
    {
        return (int) round(((float) $sar) * 100);
    }
}
