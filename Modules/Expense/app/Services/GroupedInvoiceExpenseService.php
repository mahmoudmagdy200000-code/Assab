<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\{Expense, GroupedInvoice, InvoiceDetail, ExpenseItem, ExpenseLine};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Grouped Invoice Expense Service
 */
class GroupedInvoiceExpenseService
{
    /**
     * Create Grouped Invoice Expense
     */
    public function createGroupedInvoice(array $data): Expense
    {
        $grandTotals = $this->calculateGrandTotals($data['invoices']);

        $expense = Expense::create([
            'branch_manager_id' => auth()->id(),

            'expense_type' => 'grouped_invoice',
            'status' => $data['is_draft'] ?? false ? 'draft' : 'pending',
            'total_amount' => $grandTotals['total_amount'],
            'net_amount' => $grandTotals['net_amount'],
            'vat_amount' => $grandTotals['vat_amount'],
            'payment_method' => $data['payment_method'] ?? null,
        ]);

        $groupedInvoice = GroupedInvoice::create([

            'expense_id' => $expense->id,
            'payment_type' => $data['payment_type'],
            'payment_supplier_id' => $data['payment_supplier_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'default_supplier_id' => $data['default_supplier_id'] ?? null,
        ]);

        foreach ($data['invoices'] as $invoiceData) {
            $this->createSingleInvoiceInGroup($expense, $groupedInvoice, $invoiceData);
        }

        $this->createTimelineEntry($expense, 'created', $data['is_draft'] ?? false ? 'saved_as_draft' : 'submitted');

        return $expense->load(['groupedInvoice.paymentSupplier']);
    }

    /**
     * Update Grouped Invoice Expense
     */
    public function updateGroupedInvoice(Expense $expense, array $data): Expense
    {

        // Update payment info in grouped invoice
        if (isset($data['payment_type']) || isset($data['payment_supplier_id']) || isset($data['due_date'])) {
            $groupedInvoiceUpdateData = array_filter([
                'payment_type' => $data['payment_type'] ?? null,
                'payment_supplier_id' => $data['payment_supplier_id'] ?? null,
                'default_supplier_id' => $data['default_supplier_id'] ?? null,
                'due_date' => $data['due_date'] ?? null,
            ], function ($value) {
                return $value !== null;
            });

            $expense->groupedInvoice->update($groupedInvoiceUpdateData);
        }

        $expenseUpdateData = [];
        // Update payment method
        if (isset($data['payment_method'])) {
            $expenseUpdateData['payment_method'] = $data['payment_method'];
        }

        // ✅ إضافة معالجة is_draft
        if (isset($data['is_draft'])) {
            $expenseUpdateData['status'] = $data['is_draft'] ? 'draft' : 'pending';

            if (!$data['is_draft'] && !$expense->submitted_at) {
                $expenseUpdateData['submitted_at'] = now();
            }
        }

        // Update expense if there are changes
        if (!empty($expenseUpdateData)) {
            $expense->update($expenseUpdateData);
        }
        // Recalculate totals and recreate invoices if invoices changed
        if (isset($data['invoices'])) {
            $grandTotals = $this->calculateGrandTotals($data['invoices']);

            $expense->update([
                'total_amount' => $grandTotals['total_amount'],
                'net_amount' => $grandTotals['net_amount'],
                'vat_amount' => $grandTotals['vat_amount'],
            ]);

            // Delete old invoices and their related data
            foreach ($expense->invoiceDetails as $invoice) {
                // Delete items and expense lines related to this invoice
                $expense->items()->where('invoice_detail_id', $invoice->id)->delete();
                $expense->expenseLines()->where('invoice_detail_id', $invoice->id)->delete();

                // Delete invoice attachments
                $invoice->attachments()->each(function ($attachment) {
                    try {
                        if (Storage::disk('public')->exists($attachment->file_path)) {
                            Storage::disk('public')->delete($attachment->file_path);
                        }
                        $attachment->delete();
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete attachment: ' . $e->getMessage());
                    }
                });
            }

            // Delete invoice details
            $expense->invoiceDetails()->delete();

            // Create new invoices
            foreach ($data['invoices'] as $invoiceData) {
                $this->createSingleInvoiceInGroup($expense, $expense->groupedInvoice, $invoiceData);
            }
        }

        // Delete specific attachments if requested
        if (isset($data['delete_attachments']) && is_array($data['delete_attachments'])) {
            $this->deleteAttachments($expense, $data['delete_attachments']);
        }

        $this->createTimelineEntry($expense, 'updated');

        return $expense->load(['groupedInvoice.paymentSupplier']);
    }

    /**
     * Delete specific attachments
     */
    private function deleteAttachments(Expense $expense, array $attachmentIds): void
    {
        $attachments = $expense->attachments()->whereIn('id', $attachmentIds)->get();

        foreach ($attachments as $attachment) {
            try {
                // Delete physical file
                if (Storage::disk('public')->exists($attachment->file_path)) {
                    Storage::disk('public')->delete($attachment->file_path);
                }

                // Delete database record
                $attachment->delete();
            } catch (\Exception $e) {
                Log::warning('Failed to delete attachment: ' . $e->getMessage());
            }
        }
    }

    private function createSingleInvoiceInGroup(Expense $expense, GroupedInvoice $groupedInvoice, array $invoiceData): void
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

        if (!empty($invoiceData['is_tax_invoice']) && !empty($invoiceData['tax_invoice_details'])) {
            $invoicePayload['tax_supplier_name'] = $invoiceData['tax_invoice_details']['supplier_name'] ?? null;
            $invoicePayload['tax_net_amount'] = $invoiceData['tax_invoice_details']['net_amount'] ?? 0;
            $invoicePayload['tax_vat_amount'] = $invoiceData['tax_invoice_details']['vat_amount'] ?? 0;
            $invoicePayload['tax_total_amount'] = $invoiceData['tax_invoice_details']['total_amount'] ?? 0;
        }

        $invoice = InvoiceDetail::create($invoicePayload);

        if (!empty($invoiceData['items'])) {
            foreach ($invoiceData['items'] as $item) {
                ExpenseItem::create([
                    'expense_id' => $expense->id,
                    'invoice_detail_id' => $invoice->id,
                    'category_id' => $item['category_id'],
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_amount' => $item['quantity'] * $item['unit_price'],
                ]);
            }
        }

        if (!empty($invoiceData['expenses'])) {
            foreach ($invoiceData['expenses'] as $expenseLine) {
                ExpenseLine::create([
                    'expense_id' => $expense->id,
                    'invoice_detail_id' => $invoice->id,
                    'category_id' => $expenseLine['category_id'],
                    'name' => $expenseLine['name'],
                    'price' => $expenseLine['price'],
                ]);
            }
        }

        // Upload new receipts WITHOUT deleting old ones
        if (!empty($invoiceData['invoice_receipts'])) {
            foreach ($invoiceData['invoice_receipts'] as $file) {
                $this->uploadInvoiceReceipt($expense, $invoice, $file);
            }
        }
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

            if (!empty($invoiceData['items'])) {
                foreach ($invoiceData['items'] as $item) {
                    $itemsTotal += $item['quantity'] * $item['unit_price'];
                }
            }

            if (!empty($invoiceData['expenses'])) {
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
        $filename = 'invoice_' . $invoice->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('expenses/invoices', $filename, 'public');

        $expense->attachments()->create([
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
            'invoice_detail_id' => $invoice->id,
        ]);
    }

    private function createTimelineEntry(Expense $expense, string $action, string $status = null): void
    {
        $expense->timelines()->create([
            'action' => $action,
            'performed_by' => auth()->id(),
            'performed_by_type' => 'branch_manager',
            'status' => $status ?? $action,
            'notes' => null,
        ]);
    }
}
