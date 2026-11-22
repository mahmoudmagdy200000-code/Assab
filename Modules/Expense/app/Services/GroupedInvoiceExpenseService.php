<?php

namespace Modules\Expense\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Custody\Services\ExpenseCustodyService;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseItem;
use Modules\Expense\Models\ExpenseLine;
use Modules\Expense\Models\GroupedInvoice;
use Modules\Expense\Models\InvoiceDetail;

/**
 * Grouped Invoice Expense Service
 */
class GroupedInvoiceExpenseService
{
    public function __construct(private SupplierBrandScopeService $supplierScope) {}

    /**
     * Create Grouped Invoice Expense
     */
    public function createGroupedInvoice(array $data): Expense
    {
        // One unit of work: the expense, its children, the custody charge (EXP-02) and the audit row
        // succeed together or not at all — also for callers that do not open a transaction themselves.
        return DB::transaction(fn (): Expense => $this->persistGroupedInvoice($data));
    }

    private function persistGroupedInvoice(array $data): Expense
    {
        $this->supplierScope->assertPayloadVisible(auth()->user()?->branch_id, $data);

        $grandTotals = $this->calculateGrandTotals($data['invoices']);

        $expense = Expense::create([
            'branch_manager_id' => \Modules\Expense\Services\ExpenseActorContext::branchManagerId(),
            'expense_type' => 'grouped_invoice',
            'status' => $data['is_draft'] ?? false ? 'draft' : 'pending',
            'submitted_at' => ($data['is_draft'] ?? false) ? null : now(),
            'total_amount' => $grandTotals['total_amount'],
            'net_amount' => $grandTotals['net_amount'],
            'vat_amount' => $grandTotals['vat_amount'],
            'payment_method' => $data['payment_method'] ?? null,
        ]);

        $groupedInvoice = GroupedInvoice::create([
            'expense_id' => $expense->id,
            'payment_type' => $data['payment_type'],
            'paid_amount' => $this->paidAmount($data['payment_type'], $data['paid_amount'] ?? null, $grandTotals['total_amount']),
            'payment_supplier_id' => $data['payment_supplier_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'default_supplier_id' => $data['default_supplier_id'] ?? null,
        ]);

        foreach ($data['invoices'] as $invoiceData) {
            $this->createSingleInvoiceInGroup($expense, $groupedInvoice, $invoiceData);
        }
        $this->synchronizeInvoicePayments($expense, $groupedInvoice);

        // EXP-02 / EXP-05: a non-draft create is a submission — charge the branch custody once, or refuse it.
        if (! ($data['is_draft'] ?? false)) {
            app(ExpenseCustodyService::class)->reserve($expense);
        }

        $this->createTimelineEntry($expense, 'created', $data['is_draft'] ?? false ? 'saved_as_draft' : 'submitted');

        // Non-draft create = submission — bridge to the accountant dashboard.
        if (! ($data['is_draft'] ?? false)) {
            event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense->fresh()));
        }

        return $expense->load(['groupedInvoice.paymentSupplier']);
    }

    /**
     * Update Grouped Invoice Expense
     */
    public function updateGroupedInvoice(Expense $expense, array $data): Expense
    {
        $this->supplierScope->assertPayloadVisible(auth()->user()?->branch_id, $data);

        $wasDraft = $expense->status === 'draft';

        DB::beginTransaction();
        try {
            // Update payment info in grouped invoice
            if (isset($data['payment_type']) || isset($data['payment_supplier_id']) || isset($data['due_date']) || isset($data['paid_amount'])) {
                $expense->groupedInvoice->update(array_filter([
                    'payment_type' => $data['payment_type'] ?? null,
                    'paid_amount' => $data['paid_amount'] ?? null,
                    'payment_supplier_id' => $data['payment_supplier_id'] ?? null,
                    'default_supplier_id' => $data['default_supplier_id'] ?? null,
                    'due_date' => $data['due_date'] ?? null,
                ], fn ($v) => ! is_null($v)));
            }

            // Update expense main data
            $expenseUpdate = [];

            if (isset($data['payment_method'])) {
                $expenseUpdate['payment_method'] = $data['payment_method'];
            }

            // Draft handling
            if (isset($data['is_draft'])) {
                $expenseUpdate['status'] = $data['is_draft'] ? 'draft' : 'pending';
                if (! $data['is_draft'] && ! $expense->submitted_at) {
                    $expenseUpdate['submitted_at'] = now();
                }
            }

            if (! empty($expenseUpdate)) {
                $expense->update($expenseUpdate);
            }

            // Partial Update for Single Invoice
            if (! empty($data['invoice_id'])) {
                $invoice = $expense->invoiceDetails()->where('id', $data['invoice_id'])->first();

                if (! $invoice) {
                    throw new \Exception('Invoice not found');
                }

                $updatePayload = array_filter([
                    'invoice_number' => $data['invoice_number'] ?? null,
                    'issue_date' => $data['issue_date'] ?? null,
                    'tax_id' => $data['tax_id'] ?? null,
                    'supplier_id' => $data['supplier_id'] ?? null,
                ], fn ($v) => ! is_null($v));

                if (! empty($updatePayload)) {
                    $invoice->update($updatePayload);
                }

                // Upload new receipts for this invoice
                if (! empty($data['invoice_receipts'])) {
                    foreach ($data['invoice_receipts'] as $file) {
                        $this->uploadInvoiceReceipt($expense, $invoice, $file);
                    }
                }
            }

            // Full invoices replacement (UPDATED LOGIC)
            if (isset($data['invoices'])) {
                $grandTotals = $this->calculateGrandTotals($data['invoices']);

                $expense->update([
                    'total_amount' => $grandTotals['total_amount'],
                    'net_amount' => $grandTotals['net_amount'],
                    'vat_amount' => $grandTotals['vat_amount'],
                ]);

                // Track which invoices were updated (to delete the rest)
                $updatedInvoiceIds = [];

                foreach ($data['invoices'] as $invoiceData) {
                    // Check if this is an update (has 'id') or new invoice
                    if (! empty($invoiceData['id'])) {
                        // UPDATE EXISTING INVOICE
                        $existingInvoice = $expense->invoiceDetails()->find($invoiceData['id']);

                        if (! $existingInvoice) {
                            throw new \Exception("Invoice with ID {$invoiceData['id']} not found");
                        }

                        $updatedInvoiceIds[] = $existingInvoice->id;

                        // Update invoice details
                        $existingInvoice->update([
                            'supplier_id' => $invoiceData['supplier_id'],
                            'invoice_number' => $invoiceData['invoice_number'],
                            'issue_date' => $invoiceData['issue_date'],
                            'is_tax_invoice' => $invoiceData['is_tax_invoice'],
                            'tax_id' => $invoiceData['tax_id'] ?? null,
                        ]);

                        // Handle attachments deletion for this invoice
                        if (! empty($invoiceData['delete_attachments'])) {
                            $attachmentsToDelete = $existingInvoice->attachments()
                                ->whereIn('id', $invoiceData['delete_attachments'])
                                ->get();

                            foreach ($attachmentsToDelete as $attachment) {
                                if (Storage::disk('public')->exists($attachment->file_path)) {
                                    Storage::disk('public')->delete($attachment->file_path);
                                }
                                $attachment->delete();
                            }
                        }

                        // Add new receipts if provided
                        if (! empty($invoiceData['invoice_receipts'])) {
                            foreach ($invoiceData['invoice_receipts'] as $file) {
                                $this->uploadInvoiceReceipt($expense, $existingInvoice, $file);
                            }
                        }

                        // Update items if provided
                        if (isset($invoiceData['items'])) {
                            // Delete old items
                            $expense->items()->where('invoice_detail_id', $existingInvoice->id)->delete();

                            // Create new items
                            foreach ($invoiceData['items'] as $itemData) {
                                $this->createInvoiceItem($expense, $existingInvoice, $itemData);
                            }
                        }

                        // Update expense lines if provided
                        if (isset($invoiceData['expenses'])) {
                            // Delete old expense lines
                            $expense->expenseLines()->where('invoice_detail_id', $existingInvoice->id)->delete();

                            // Create new expense lines
                            foreach ($invoiceData['expenses'] as $expenseLineData) {
                                $this->createExpenseLine($expense, $existingInvoice, $expenseLineData);
                            }
                        }
                    } else {
                        // CREATE NEW INVOICE
                        $newInvoice = $this->createSingleInvoiceInGroup($expense, $expense->groupedInvoice, $invoiceData);
                        $updatedInvoiceIds[] = $newInvoice->id;
                    }
                }

                // Delete invoices that were not in the update list
                $invoicesToDelete = $expense->invoiceDetails()
                    ->whereNotIn('id', $updatedInvoiceIds)
                    ->get();

                foreach ($invoicesToDelete as $invoice) {
                    // Delete items and lines
                    $expense->items()->where('invoice_detail_id', $invoice->id)->delete();
                    $expense->expenseLines()->where('invoice_detail_id', $invoice->id)->delete();

                    // Delete attachments
                    $invoice->attachments()->each(function ($attachment) {
                        if (Storage::disk('public')->exists($attachment->file_path)) {
                            Storage::disk('public')->delete($attachment->file_path);
                        }
                        $attachment->delete();
                    });

                    $invoice->delete();
                }
            }

            // The group has one actual payment amount. Persist its deterministic allocation on each
            // invoice so reports and later edits never have to guess which invoices were paid.
            $this->synchronizeInvoicePayments($expense, $expense->groupedInvoice->fresh());

            // Delete specific attachments (global deletion)
            if (! empty($data['delete_attachments'])) {
                $this->deleteAttachments($expense, $data['delete_attachments']);
            }

            $this->createTimelineEntry($expense, 'updated');

            // EXP-02 / EXP-05: a draft flipped to pending is a submission — charge the branch custody once,
            // or refuse it (the rollback below leaves the draft untouched).
            $submitted = $wasDraft && $expense->fresh()->status === 'pending';
            if ($submitted) {
                app(ExpenseCustodyService::class)->reserve($expense->fresh());
            }

            DB::commit();

            // Draft flipped to pending = a submission — bridge it (idempotent).
            if ($submitted) {
                event(new \Modules\Expense\Events\ExpenseSubmittedEvent($expense->fresh()));
            }

            return $expense->fresh(['groupedInvoice.paymentSupplier', 'invoiceDetails.attachments', 'items', 'expenseLines', 'attachments']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Update Grouped Invoice Failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete specific attachments
     */
    private function deleteAttachments(Expense $expense, array $attachmentIds): void
    {
        $attachments = $expense->attachments()->whereIn('id', $attachmentIds)->get();

        foreach ($attachments as $attachment) {
            try {
                if (Storage::disk('public')->exists($attachment->file_path)) {
                    Storage::disk('public')->delete($attachment->file_path);
                }

                $attachment->delete();
            } catch (\Exception $e) {
                Log::warning('Failed to delete attachment: '.$e->getMessage());
            }
        }
    }

    /**
     * Create single invoice in group and return the created invoice
     */
    private function createSingleInvoiceInGroup(Expense $expense, GroupedInvoice $groupedInvoice, array $invoiceData): InvoiceDetail
    {
        $invoicePayload = [
            'expense_id' => $expense->id,
            'grouped_invoice_id' => $groupedInvoice->id,
            'invoice_number' => $invoiceData['invoice_number'],
            'issue_date' => $invoiceData['issue_date'],
            'is_tax_invoice' => $invoiceData['is_tax_invoice'],
            'tax_id' => $invoiceData['tax_id'] ?? null,
            'supplier_id' => $invoiceData['supplier_id'],
        ];

        if (! empty($invoiceData['is_tax_invoice']) && ! empty($invoiceData['tax_invoice_details'])) {
            $invoicePayload['tax_supplier_name'] = $invoiceData['tax_invoice_details']['supplier_name'] ?? null;
            $invoicePayload['tax_net_amount'] = $invoiceData['tax_invoice_details']['net_amount'] ?? 0;
            $invoicePayload['tax_vat_amount'] = $invoiceData['tax_invoice_details']['vat_amount'] ?? 0;
            $invoicePayload['tax_total_amount'] = $invoiceData['tax_invoice_details']['total_amount'] ?? 0;
        }

        $invoice = InvoiceDetail::create($invoicePayload);

        if (! empty($invoiceData['items'])) {
            foreach ($invoiceData['items'] as $item) {
                $this->createInvoiceItem($expense, $invoice, $item);
            }
        }

        if (! empty($invoiceData['expenses'])) {
            foreach ($invoiceData['expenses'] as $expenseLine) {
                $this->createExpenseLine($expense, $invoice, $expenseLine);
            }
        }

        // Upload new receipts WITHOUT deleting old ones
        if (! empty($invoiceData['invoice_receipts'])) {
            foreach ($invoiceData['invoice_receipts'] as $file) {
                $this->uploadInvoiceReceipt($expense, $invoice, $file);
            }
        }

        return $invoice;
    }

    private function synchronizeInvoicePayments(Expense $expense, GroupedInvoice $group): void
    {
        $invoices = $expense->invoiceDetails()->with(['items', 'expenseLines'])->orderBy('created_at')->orderBy('id')->get();
        $totals = $invoices->map(fn (InvoiceDetail $invoice): int => $this->invoiceTotalHalalas($invoice))->all();
        $total = array_sum($totals);
        $paymentType = $group->payment_type;
        $paid = $this->toHalalas($this->paidAmount($paymentType, (float) $group->paid_amount, $total / 100));

        if ($paid > $total || ($paymentType === 'partial' && $paid <= 0)) {
            throw new InvalidArgumentException('Grouped invoice paid amount must be greater than zero and no more than the invoice total.');
        }

        $allocated = match ($paymentType) {
            'full' => $totals,
            'partial' => $this->allocateProportionally($totals, $paid),
            default => array_fill(0, count($totals), 0),
        };

        foreach ($invoices as $index => $invoice) {
            $invoicePaid = $allocated[$index] ?? 0;
            $invoice->update([
                'payment_type' => $invoicePaid === 0 ? 'deferred' : ($invoicePaid >= $totals[$index] ? 'full' : 'partial'),
                'paid_amount' => number_format($invoicePaid / 100, 2, '.', ''),
                'due_date' => $group->due_date,
                'payment_supplier_id' => $group->payment_supplier_id,
            ]);
        }

        $group->update(['paid_amount' => number_format($paid / 100, 2, '.', '')]);
    }

    /** Allocate integer halalas proportionally; leftover halalas go to earlier invoice rows. */
    private function allocateProportionally(array $invoiceTotals, int $paid): array
    {
        $total = array_sum($invoiceTotals);
        if ($total <= 0) {
            return array_fill(0, count($invoiceTotals), 0);
        }

        $allocations = [];
        $allocated = 0;
        foreach ($invoiceTotals as $invoiceTotal) {
            $share = intdiv($paid * $invoiceTotal, $total);
            $allocations[] = $share;
            $allocated += $share;
        }

        for ($i = 0; $allocated < $paid && $i < count($allocations); $i++) {
            if ($allocations[$i] < $invoiceTotals[$i]) {
                $allocations[$i]++;
                $allocated++;
            }
            if ($i === count($allocations) - 1 && $allocated < $paid) {
                $i = -1;
            }
        }

        return $allocations;
    }

    private function invoiceTotalHalalas(InvoiceDetail $invoice): int
    {
        $items = $invoice->items->sum(fn ($item): float => (float) $item->quantity * (float) $item->unit_price);
        $expenses = $invoice->expenseLines->sum(fn ($line): float => (float) $line->price);

        return $this->toHalalas($items + $expenses);
    }

    private function paidAmount(string $paymentType, ?float $paidAmount, float $total): float
    {
        return match ($paymentType) {
            'full' => $total,
            'partial' => $paidAmount ?? 0.0,
            default => 0.0,
        };
    }

    private function toHalalas(float|int|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Create invoice item
     */
    private function createInvoiceItem(Expense $expense, InvoiceDetail $invoice, array $itemData): ExpenseItem
    {
        return ExpenseItem::create([
            'expense_id' => $expense->id,
            'invoice_detail_id' => $invoice->id,
            'category_id' => $itemData['category_id'],
            'name' => $itemData['name'],
            'quantity' => $itemData['quantity'],
            'unit_price' => $itemData['unit_price'],
            'total_amount' => $itemData['quantity'] * $itemData['unit_price'],
        ]);
    }

    /**
     * Create expense line
     */
    private function createExpenseLine(Expense $expense, InvoiceDetail $invoice, array $expenseLineData): ExpenseLine
    {
        return ExpenseLine::create([
            'expense_id' => $expense->id,
            'invoice_detail_id' => $invoice->id,
            'category_id' => $expenseLineData['category_id'],
            'name' => $expenseLineData['name'],
            'price' => $expenseLineData['price'],
        ]);
    }

    /**
     * Calculate grand totals for all invoices
     */
    private function calculateGrandTotals(array $invoices): array
    {
        $grandTotal = 0;

        foreach ($invoices as $invoiceData) {
            $itemsTotal = 0;
            $expensesTotal = 0;

            if (! empty($invoiceData['items'])) {
                foreach ($invoiceData['items'] as $item) {
                    $itemsTotal += $item['quantity'] * $item['unit_price'];
                }
            }

            if (! empty($invoiceData['expenses'])) {
                foreach ($invoiceData['expenses'] as $expense) {
                    $expensesTotal += $expense['price'];
                }
            }

            $grandTotal += $itemsTotal + $expensesTotal;
        }

        $vatAmount = $grandTotal * 0.15;
        $netAmount = $grandTotal - $vatAmount;

        return [
            'total_amount' => round($grandTotal, 2),
            'net_amount' => round($netAmount, 2),
            'vat_amount' => round($vatAmount, 2),
        ];
    }

    /**
     * Calculate total amount (helper for validation)
     */
    public function calculateTotalAmount(array $invoices): float
    {
        return $this->calculateGrandTotals($invoices)['total_amount'];
    }

    /**
     * Upload invoice receipt (adds new without deleting old)
     */
    private function uploadInvoiceReceipt(Expense $expense, InvoiceDetail $invoice, $file): void
    {
        $filename = 'invoice_'.$invoice->id.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('expenses/invoices', $filename, 'public');

        $expense->attachments()->create([
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
            'invoice_detail_id' => $invoice->id,
        ]);
    }

    /**
     * Create timeline entry
     */
    private function createTimelineEntry(Expense $expense, string $action, ?string $status = null): void
    {
        $expense->timelines()->create([
            'action' => $action,
            'performed_by' => auth()->id(),
            'performed_by_type' => ExpenseActorContext::actorType(),
            'status' => $status ?? $action,
            'notes' => null,
        ]);
    }
}
