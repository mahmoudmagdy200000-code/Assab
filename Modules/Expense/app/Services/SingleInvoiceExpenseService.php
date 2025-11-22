<?php

namespace Modules\Expense\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Expense\Models\{
    Expense,
    InvoiceDetail,
    ExpenseItem,
    ExpenseLine,
    ExpenseAttachment
};

/**
 * Single Invoice Expense Service
 * For expenses > 500 SAR
 */
class SingleInvoiceExpenseService
{
    /**
     * Create Single Invoice Expense
     */
    public function createSingleInvoice(array $data): Expense
    {
        $totals = $this->calculateTotals($data);

        $expense = Expense::create([
            'branch_manager_id' => auth()->id(),
            'expense_type' => 'single_invoice',
            'status' => $data['is_draft'] ?? false ? 'draft' : 'pending',
            'total_amount' => $data['total_amount'],
            'net_amount' => $totals['net_amount'],
            'vat_amount' => $totals['vat_amount'],
            'payment_method' => $data['payment_method'] ?? null,
            'supplier_id' => $data['supplier_id'],
        ]);

        $invoice = InvoiceDetail::create([
            'expense_id' => $expense->id,
            'supplier_id' => $data['supplier_id'],
            'invoice_number' => $data['invoice_number'],
            'issue_date' => $data['issue_date'],
            'is_tax_invoice' => $data['is_tax_invoice'],
            'tax_id' => $data['tax_id'] ?? null,
            'payment_type' => $data['payment_type'],
            'paid_amount' => $this->getPaidAmount($data),
            'due_date' => $data['due_date'] ?? null,
        ]);

        if (!empty($data['is_tax_invoice']) && !empty($data['tax_invoice_details'])) {
            $invoice->update([
                'tax_supplier_name' => $data['tax_invoice_details']['supplier_name'] ?? null,
                'tax_net_amount' => $data['tax_invoice_details']['net_amount'] ?? null,
                'tax_vat_amount' => $data['tax_invoice_details']['vat_amount'] ?? null,
                'tax_total_amount' => $data['tax_invoice_details']['total_amount'] ?? null,
            ]);
        }

        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
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

        if (!empty($data['expenses'])) {
            foreach ($data['expenses'] as $expenseLine) {
                ExpenseLine::create([
                    'expense_id' => $expense->id,
                    'invoice_detail_id' => $invoice->id,
                    'category_id' => $expenseLine['category_id'],
                    'name' => $expenseLine['name'],
                    'price' => $expenseLine['price'],
                ]);
            }
        }

        if (isset($data['invoice_receipt']) && is_array($data['invoice_receipt'])) {
            foreach ($data['invoice_receipt'] as $file) {
                $this->uploadInvoiceReceipt($expense, $file);
            }
        }

        $this->createTimelineEntry($expense, 'created', $data['is_draft'] ?? false ? 'saved_as_draft' : 'submitted');

        return $expense;
    }

    /**
     * Update Single Invoice Expense
     */
    public function updateSingleInvoice(Expense $expense, array $data): Expense
    {
        // Update basic expense info
        $updateData = [];

        if (isset($data['supplier_id'])) {
            $updateData['supplier_id'] = $data['supplier_id'];
        }

        if (isset($data['payment_method'])) {
            $updateData['payment_method'] = $data['payment_method'];
        }

        if (isset($data['is_draft'])) {

            $updateData['status'] = $data['is_draft'] ? 'draft' : 'pending';


            if (!$data['is_draft'] && !$expense->submitted_at) {
                $updateData['submitted_at'] = now();
            }
        }

        // Recalculate totals if items/expenses changed
        if (isset($data['items']) || isset($data['expenses']) || isset($data['total_amount'])) {
            $mergedData = array_merge($expense->toArray(), $data);
            $totals = $this->calculateTotals($mergedData);

            $updateData['total_amount'] = $data['total_amount'] ?? $totals['total_amount'];
            $updateData['net_amount'] = $totals['net_amount'];
            $updateData['vat_amount'] = $totals['vat_amount'];
        }

        if (!empty($updateData)) {
            $expense->update($updateData);
        }

        // Update invoice details
        $invoiceDetail = $expense->invoiceDetails()->first();
        if ($invoiceDetail) {
            $invoiceUpdateData = array_filter([
                'supplier_id' => $data['supplier_id'] ?? null,
                'invoice_number' => $data['invoice_number'] ?? null,
                'issue_date' => $data['issue_date'] ?? null,
                'is_tax_invoice' => $data['is_tax_invoice'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'due_date' => $data['due_date'] ?? null,
            ], function ($value) {
                return $value !== null;
            });

            // Calculate paid amount if payment_type changed
            if (isset($data['payment_type'])) {
                $invoiceUpdateData['paid_amount'] = $this->getPaidAmount(array_merge(
                    $expense->toArray(),
                    $data
                ));
            } elseif (isset($data['paid_amount'])) {
                $invoiceUpdateData['paid_amount'] = $data['paid_amount'];
            }

            $invoiceDetail->update($invoiceUpdateData);

            // Update tax invoice details if applicable
            if (!empty($data['is_tax_invoice']) && !empty($data['tax_invoice_details'])) {
                $invoiceDetail->update([
                    'tax_supplier_name' => $data['tax_invoice_details']['supplier_name'] ?? $invoiceDetail->tax_supplier_name,
                    'tax_net_amount' => $data['tax_invoice_details']['net_amount'] ?? $invoiceDetail->tax_net_amount,
                    'tax_vat_amount' => $data['tax_invoice_details']['vat_amount'] ?? $invoiceDetail->tax_vat_amount,
                    'tax_total_amount' => $data['tax_invoice_details']['total_amount'] ?? $invoiceDetail->tax_total_amount,
                ]);
            }
        }

        // Update items if provided
        if (isset($data['items'])) {
            $expense->items()->delete();

            foreach ($data['items'] as $item) {
                ExpenseItem::create([
                    'expense_id' => $expense->id,
                    'invoice_detail_id' => $invoiceDetail->id,
                    'category_id' => $item['category_id'],
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_amount' => $item['quantity'] * $item['unit_price'],
                ]);
            }
        }

        // Update expense lines if provided
        if (isset($data['expenses'])) {
            $expense->expenseLines()->delete();

            foreach ($data['expenses'] as $expenseLine) {
                ExpenseLine::create([
                    'expense_id' => $expense->id,
                    'invoice_detail_id' => $invoiceDetail->id,
                    'category_id' => $expenseLine['category_id'],
                    'name' => $expenseLine['name'],
                    'price' => $expenseLine['price'],
                ]);
            }
        }

        // Delete specific attachments if requested
        if (isset($data['delete_attachments']) && is_array($data['delete_attachments'])) {
            $this->deleteAttachments($expense, $data['delete_attachments']);
        }

        // Upload new receipts WITHOUT deleting old ones
        if (isset($data['invoice_receipt']) && is_array($data['invoice_receipt'])) {
            foreach ($data['invoice_receipt'] as $file) {
                $this->uploadInvoiceReceipt($expense, $file);
            }
        }

        $this->createTimelineEntry($expense, 'updated');

        return $expense;
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

    /**
     * Calculate totals for single invoice
     */
    public function calculateTotals(array $data): array
    {
        $itemsTotal = 0;
        $expensesTotal = 0;

        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $itemsTotal += $item['quantity'] * $item['unit_price'];
            }
        }

        if (!empty($data['expenses'])) {
            foreach ($data['expenses'] as $expense) {
                $expensesTotal += $expense['price'];
            }
        }

        $subTotal = $itemsTotal + $expensesTotal;
        $vatRate = $data['vat_rate'] ?? 0.15;
        $vatAmount = round($subTotal * $vatRate, 2);
        $totalAmount = round($subTotal + $vatAmount, 2);

        return [
            'net_amount' => $subTotal,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
        ];
    }

    /**
     * Calculate total amount (helper for validation)
     */
    public function calculateTotalAmount(array $data): float
    {
        return $this->calculateTotals($data)['total_amount'];
    }

    /**
     * Get paid amount based on payment type
     */
    private function getPaidAmount(array $data): float
    {
        return match ($data['payment_type']) {
            'full' => $data['total_amount'] ?? $this->calculateTotalAmount($data),
            'partial' => $data['paid_amount'] ?? 0,
            'deferred' => 0,
            default => 0,
        };
    }

    /**
     * Upload invoice receipt (adds new without deleting old)
     */
    private function uploadInvoiceReceipt(Expense $expense, $file): void
    {
        $filename = 'expense_' . $expense->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('expenses/receipts', $filename, 'public');

        $expense->attachments()->create([
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
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
