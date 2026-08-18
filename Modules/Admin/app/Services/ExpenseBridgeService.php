<?php

namespace Modules\Admin\Services;

use App\Support\PublicUrl;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\Expense\Enums\ExpenseApprovalStage;
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

        // A rejected record the branch manager has RESUBMITTED opens a new
        // cycle: without this the operation stayed «مرفوضة» forever and the
        // corrected expense never came back to the accountant's inbox
        // (meeting 2026-08-14 — «ترجع لمدير الفرع … ياخد عليها اكشن»).
        if ($existing
            && $existing->status === Operation::STATUS_REJECTED
            && $expense->status === 'pending'
            && $expense->approval_stage === null
        ) {
            $this->reopen($existing, $expense);
            $existing->refresh();
        }

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
            $this->applyBrandOwnerDecision($existing, $expense);

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
        $this->applyBrandOwnerDecision($op, $expense);

        return $op;
    }

    /**
     * Cycle 1 of the meeting-2026-08-14 chain: the brand owner decided in the
     * mobile app, so the mirrored operation is CLOSED at the same moment.
     * `final-approved` / `rejected` are both terminal in OperationService, which
     * is exactly the ask — «المحاسب ورئيس الحسابات ميقدروش ياخدوا عليها أي
     * اكشن» — and the decision rides in the payload so the dashboard can render
     * «موافق عليه من <اسم العلامة التجارية>».
     *
     * The brand owner is not an `asab_users` row, so the actor id columns stay
     * NULL (they are FKs) and only the name is carried.
     */
    private function applyBrandOwnerDecision(Operation $op, Expense $expense): void
    {
        $stage = $expense->approval_stage;
        if ($stage === null || $stage->isAccountingCycle() || $op->status !== Operation::STATUS_PENDING) {
            return;
        }

        $approved = $stage === ExpenseApprovalStage::BRAND_OWNER_APPROVED;
        $by = $expense->decided_by_name;

        DB::transaction(function () use ($op, $expense, $stage, $approved, $by) {
            $payload = $op->payload ?? [];
            $payload['legacyStatus'] = $expense->status;
            $payload['legacyDecision'] = [
                'stage' => $stage->value,
                'stageLabelAr' => $stage->labelAr(),
                'byName' => $by,
                'byRole' => 'brand_owner',
                'byRoleLabelAr' => 'مالك العلامة التجارية',
                'decidedAt' => optional($expense->decided_at)->toIso8601String(),
            ];

            $op->update([
                'payload' => $payload,
                'status' => $approved ? Operation::STATUS_FINAL : Operation::STATUS_REJECTED,
                'final_approved_at' => $approved ? ($expense->approved_at ?? now()) : null,
                'rejected_at' => $approved ? null : ($expense->rejected_at ?? now()),
                'reject_reason' => $approved ? null : $expense->rejection_reason,
            ]);

            ApprovalStep::create([
                'operation_id' => $op->id,
                'stage_id' => $approved ? 'final' : 'rejected',
                'action' => ($approved ? 'اعتمدها مالك العلامة التجارية من التطبيق' : 'رفضها مالك العلامة التجارية من التطبيق')
                    .($approved || ! $expense->rejection_reason ? '' : ' — السبب: '.$expense->rejection_reason),
                'actor_label' => $by ?? 'مالك العلامة التجارية',
                'note' => $approved ? null : $expense->rejection_reason,
                'meta' => ['legacyDecision' => $stage->value],
                'occurred_at' => $expense->decided_at ?? now(),
            ]);
        });

        $op->refresh();
    }

    /**
     * Put a rejected operation back in the accountant's queue after the branch
     * manager corrected and resubmitted the mobile expense.
     */
    private function reopen(Operation $op, Expense $expense): void
    {
        DB::transaction(function () use ($op, $expense) {
            $op->update([
                'status' => Operation::STATUS_PENDING,
                'reject_reason' => null,
                'rejected_at' => null,
                'rejected_by_id' => null,
                'approved_by_id' => null,
                'approved_at' => null,
            ]);

            ApprovalStep::create([
                'operation_id' => $op->id,
                'stage_id' => 'submit',
                'action' => 'أعاد الفرع إرسال السجل بعد التعديل: '.$op->public_id,
                'actor_label' => $expense->branchManager?->name ?? 'تطبيق الفرع',
                'occurred_at' => now(),
            ]);
        });
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
                        'public_url' => PublicUrl::for($a->file_path),
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
            return $this->fallbackInvoice($expense);
        }

        $attachments = ExpenseAttachment::where('expense_id', $expense->id)
            ->get()->groupBy('invoice_detail_id');

        // Non-tax invoices carry no tax_* amounts — their total lives on the
        // line items (grouped) or on the expense itself (single invoice).
        // Without this fallback every non-tax invoice bridged as 0.00 SAR in
        // the accountant's ACC-2 «الفواتير» table.
        $itemTotals = \Modules\Expense\Models\ExpenseItem::where('expense_id', $expense->id)
            ->whereNotNull('invoice_detail_id')
            ->selectRaw('invoice_detail_id, SUM(total_amount) as t')
            ->groupBy('invoice_detail_id')->pluck('t', 'invoice_detail_id');
        $lineTotals = \Modules\Expense\Models\ExpenseLine::where('expense_id', $expense->id)
            ->whereNotNull('invoice_detail_id')
            ->selectRaw('invoice_detail_id, SUM(price) as t')
            ->groupBy('invoice_detail_id')->pluck('t', 'invoice_detail_id');
        $single = $details->count() === 1;

        // Vendor fallback chain (meeting 2026-07-29 «بيانات ناقصة/غلط»): the
        // free-text tax name is only present on tax invoices, so fall back to
        // the invoice's picked supplier, then the expense-level supplier.
        return $details->values()->map(function (InvoiceDetail $d) use ($expense, $attachments, $itemTotals, $lineTotals, $single) {
            $lineSum = (float) ($itemTotals[$d->id] ?? 0) + (float) ($lineTotals[$d->id] ?? 0);
            // A ZERO tax_total_amount is as absent as a null one: the mobile app
            // writes 0.00 on a non-tax invoice, and taking it literally rendered
            // «0.00 ر.س» in the accountant's invoice table under a header that
            // showed the real 3,008.00 (EXP-0011, prod E2E 2026-07-31). Fall
            // through to the line sum / statement total exactly as for null.
            $taxTotal = ((float) $d->tax_total_amount) > 0 ? $d->tax_total_amount : null;
            $amount = $taxTotal
                ?? ($lineSum > 0 ? $lineSum : null)
                ?? ($single ? $expense->total_amount : null);
            $vat = $d->tax_vat_amount
                ?? ($single && $taxTotal === null ? $expense->vat_amount : null);

            return array_filter([
                'invNum' => $d->invoice_number,
                'vendor' => $d->tax_supplier_name ?? $d->supplier?->name ?? $expense->supplier?->name,
                'supplierId' => $d->supplier_id ?? $expense->supplier_id,
                'desc' => $expense->expense_type,
                'date' => optional($d->issue_date)->toDateString(),
                'amountHalalas' => $this->halalas($amount),
                'vatHalalas' => $vat === null ? null : $this->halalas($vat),
                'attachments' => $this->files($attachments->get($d->id, collect())),
            ], fn ($v) => $v !== null);
        })->all();
    }

    /**
     * A QUICK-CASH expense carries no `invoice_details` at all — the amount,
     * the supplier and the receipt hang off the expense itself. It used to
     * bridge as an EMPTY invoices[], which the accountant's ACC-2 table then
     * back-filled from the payload root: no supplier, no invoice number, no
     * description («اسم المورد مش ظاهر مع اني مختار سبلاير», 2026-08-14).
     * Synthesised here instead, from the same fallback chain the invoice rows
     * use: the expense's supplier, then the quick-cash payment supplier.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fallbackInvoice(Expense $expense): array
    {
        $quick = $expense->quickCashExpense;
        $date = $quick?->expense_date ?? $expense->submitted_at ?? $expense->created_at;
        $vat = (float) $expense->vat_amount;

        return [array_filter([
            'invNum' => $quick?->invoice_number,
            'vendor' => $expense->supplier?->name ?? $quick?->paymentSupplier?->name,
            'supplierId' => $expense->supplier_id ?? $quick?->payment_supplier_id,
            'desc' => $quick?->expense_name ?? $expense->expense_type,
            'date' => optional($date)->toDateString(),
            'amountHalalas' => $this->halalas($expense->total_amount),
            'vatHalalas' => $vat > 0 ? $this->halalas($vat) : null,
            'attachments' => $this->files(
                ExpenseAttachment::where('expense_id', $expense->id)->whereNull('invoice_detail_id')->get(),
            ),
        ], fn ($v) => $v !== null)];
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
            'publicUrl' => PublicUrl::for($a->file_path),
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
