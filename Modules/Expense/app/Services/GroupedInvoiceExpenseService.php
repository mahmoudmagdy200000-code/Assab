<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\{Expense, GroupedInvoice, InvoiceDetail, ExpenseItem, ExpenseLine};

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
        // Calculate grand totals
        $grandTotals = $this->calculateGrandTotals($data['invoices']);

        // Create main expense record
        $expense = Expense::create([
            'branch_manager_id' => auth()->id(),
            'expense_type' => 'grouped_invoice',
            'status' => $data['is_draft'] ?? false ? 'draft' : 'pending',
            'total_amount' => $grandTotals['total_amount'],
            'net_amount' => $grandTotals['net_amount'],
            'vat_amount' => $grandTotals['vat_amount'],
            'payment_method' => $data['payment_method'] ?? null,
        ]);

        // Create grouped invoice record
        $groupedInvoice = GroupedInvoice::create([
            'expense_id' => $expense->id,
            'payment_type' => $data['payment_type'],
            // 'paid_amount' => $this->getPaidAmount($data, $grandTotals['total_amount']),
            'due_date' => $data['due_date'] ?? null,
        ]);

        // Create individual invoices
        foreach ($data['invoices'] as $invoiceData) {
            $this->createSingleInvoiceInGroup($expense, $groupedInvoice, $invoiceData);
        }


        // Create timeline entry
        $this->createTimelineEntry($expense, 'created', $data['is_draft'] ?? false ? 'saved_as_draft' : 'submitted');

        return $expense;
    }

    /**
     * Update Grouped Invoice Expense
     */
    public function updateGroupedInvoice(Expense $expense, array $data): Expense
    {
        // Recalculate totals if invoices changed
        if (isset($data['invoices'])) {
            $grandTotals = $this->calculateGrandTotals($data['invoices']);

            $expense->update([
                'total_amount' => $grandTotals['total_amount'],
                'net_amount' => $grandTotals['net_amount'],
                'vat_amount' => $grandTotals['vat_amount'],
            ]);

            // Delete old invoices and create new ones
            $expense->groupedInvoice->invoiceDetails()->delete();
            $expense->items()->delete();
            $expense->expenseLines()->delete();

            foreach ($data['invoices'] as $invoiceData) {
                $this->createSingleInvoiceInGroup($expense, $expense->groupedInvoice, $invoiceData);
            }
        }

        // Update payment info
        if (isset($data['payment_type'])) {
            $expense->groupedInvoice->update([
                'payment_type' => $data['payment_type'],
                'paid_amount' => $this->getPaidAmount($data, $expense->total_amount),
                'due_date' => $data['due_date'] ?? null,
            ]);
        }

        // Create timeline entry
        $this->createTimelineEntry($expense, 'updated');

        return $expense;
    }

    private function createSingleInvoiceInGroup(Expense $expense, GroupedInvoice $groupedInvoice, array $invoiceData): void
    {
        // 🔹 بيانات الفاتورة الأساسية
        $invoicePayload = [
            'expense_id' => $expense->id,
            'grouped_invoice_id' => $groupedInvoice->id,
            'invoice_number' => $invoiceData['invoice_number'],
            'issue_date' => $invoiceData['issue_date'],
            'is_tax_invoice' => $invoiceData['is_tax_invoice'],
            'tax_id' => $invoiceData['tax_id'] ?? null,
            'supplier_id' => $invoiceData['supplier_id'],
        ];

        // 🔹 لو الفاتورة ضريبية، نضيف بيانات tax_invoice_details
        if (!empty($invoiceData['is_tax_invoice']) && !empty($invoiceData['tax_invoice_details'])) {
            $invoicePayload['tax_invoice_supplier_id'] = $invoiceData['tax_invoice_details']['supplier_id'] ?? null;  // Changed from supplier_name
            $invoicePayload['tax_net_amount'] = $invoiceData['tax_invoice_details']['net_amount'] ?? 0;
            $invoicePayload['tax_vat_amount'] = $invoiceData['tax_invoice_details']['vat_amount'] ?? 0;
            $invoicePayload['tax_total_amount'] = $invoiceData['tax_invoice_details']['total_amount'] ?? 0;
        }

        // 🔸 إنشاء السجل في جدول invoice_details
        $invoice = InvoiceDetail::create($invoicePayload);

        // باقي الأكواد كما هي 👇
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
     * Get paid amount based on payment type
     */
    private function getPaidAmount(array $data, float $totalAmount): float
    {
        return match ($data['payment_type']) {
            'full' => $totalAmount,
            'partial' => $data['paid_amount'] ?? 0,
            'deferred' => 0,
            default => 0,
        };
    }

    private function uploadInvoiceReceipt(Expense $expense, InvoiceDetail $invoice, $file): void
    {
        $filename = 'invoice_' . $invoice->id . '_' . time() . '.' . $file->getClientOriginalExtension();
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
