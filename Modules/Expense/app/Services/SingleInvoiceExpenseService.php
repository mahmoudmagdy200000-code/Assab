<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\{
    Expense,
    InvoiceDetail,
    ExpenseItem,
    ExpenseLine
};
use Illuminate\Support\Facades\Storage;

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
        // Calculate totals
        $totals = $this->calculateTotals($data);

        // Create main expense record
        $expense = Expense::create([
            'branch_manager_id' => auth()->id(),
            'expense_type' => 'single_invoice',
            'status' => $data['is_draft'] ?? false ? 'draft' : 'pending',
            'total_amount' => $totals['total_amount'],
            'net_amount' => $totals['net_amount'],
            'vat_amount' => $totals['vat_amount'],
            'payment_method' => $data['payment_method'] ?? null,
            'supplier_id' => $data['supplier_id'],
        ]);

        // Create invoice details
        $invoice = InvoiceDetail::create([
            'expense_id' => $expense->id,
            'invoice_number' => $data['invoice_number'],
            'issue_date' => $data['issue_date'],
            'is_tax_invoice' => $data['is_tax_invoice'],
            'tax_id' => $data['tax_id'] ?? null,
            'payment_type' => $data['payment_type'],
            'paid_amount' => $this->getPaidAmount($data),
            'due_date' => $data['due_date'] ?? null,
        ]);

        // Create expense items (purchases)
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

        // Create expense lines (expenses)
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

        // Upload invoice receipt
        if (isset($data['invoice_receipt'])) {
            $this->uploadInvoiceReceipt($expense, $data['invoice_receipt']);
        }

        // Create timeline entry
        $this->createTimelineEntry($expense, 'created', $data['is_draft'] ?? false ? 'saved_as_draft' : 'submitted');

        return $expense;
    }

    /**
     * Update Single Invoice Expense
     */
    public function updateSingleInvoice(Expense $expense, array $data): Expense
    {
        // Update main expense if amounts changed
        if (isset($data['items']) || isset($data['expenses'])) {
            $totals = $this->calculateTotals($data);

            $expense->update([
                'total_amount' => $totals['total_amount'],
                'net_amount' => $totals['net_amount'],
                'vat_amount' => $totals['vat_amount'],
            ]);
        }

        // Update invoice details
        if ($expense->invoiceDetails()->exists()) {
            $expense->invoiceDetails()->first()->update(array_filter([
                'invoice_number' => $data['invoice_number'] ?? null,
                'issue_date' => $data['issue_date'] ?? null,
                'is_tax_invoice' => $data['is_tax_invoice'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'paid_amount' => isset($data['payment_type']) ? $this->getPaidAmount($data) : null,
                'due_date' => $data['due_date'] ?? null,
            ]));
        }

        // Update items if provided
        if (isset($data['items'])) {
            $expense->items()->delete();

            foreach ($data['items'] as $item) {
                ExpenseItem::create([
                    'expense_id' => $expense->id,
                    'invoice_detail_id' => $expense->invoiceDetails()->first()->id,
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
                    'invoice_detail_id' => $expense->invoiceDetails()->first()->id,
                    'category_id' => $expenseLine['category_id'],
                    'name' => $expenseLine['name'],
                    'price' => $expenseLine['price'],
                ]);
            }
        }

        // Upload new receipt if provided
        if (isset($data['invoice_receipt'])) {
            $this->uploadInvoiceReceipt($expense, $data['invoice_receipt']);
        }

        // Create timeline entry
        $this->createTimelineEntry($expense, 'updated');

        return $expense;
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
            'full' => $this->calculateTotalAmount($data),
            'partial' => $data['paid_amount'],
            'deferred' => 0,
            default => 0,
        };
    }

    /**
     * Get previous invoices for reuse
     */
    public function getPreviousInvoices(int $branchManagerId, ?string $search = null)
    {
        $query = Expense::where('branch_manager_id', $branchManagerId)
            ->where('expense_type', 'single_invoice')
            ->whereNotNull('submitted_at')
            ->with(['supplier', 'invoiceDetails'])
            ->orderBy('submitted_at', 'desc')
            ->limit(20);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('invoiceDetails', function ($sq) use ($search) {
                    $sq->where('invoice_number', 'like', "%{$search}%");
                })->orWhereHas('supplier', function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%");
                });
            });
        }

        return $query->get()->map(function ($expense) {
            return [
                'id' => $expense->id,
                'invoice_name' => $expense->invoiceDetails->first()->invoice_number ?? 'N/A',
                'type' => 'Single Invoice',
                'date_time' => $expense->submitted_at?->format('Y-m-d H:i'),
                'amount' => $expense->total_amount,
                'status' => $expense->status,
            ];
        });
    }

    private function uploadInvoiceReceipt(Expense $expense, $file): void
    {
        $filename = 'expense_' . $expense->id . '_' . time() . '.' . $file->getClientOriginalExtension();
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
