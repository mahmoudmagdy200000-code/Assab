<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\{Expense, QuickCashExpense, QuickCashItem};
use Illuminate\Support\Facades\Storage;

/**
 * Quick Cash Expense Service
 * For expenses < 500 SAR
 */
class QuickCashExpenseService
{
    /**
     * Create Quick Cash Expense
     */
    public function createQuickCashExpense(array $data): Expense
    {
        // Calculate VAT
        $vatCalculation = $this->calculateVAT($data['total_amount'], $data['has_vat'] ?? false);

        // Create main expense record
        $expense = Expense::create([
            'branch_manager_id' => auth()->id(),
            'expense_type' => 'quick_cash',
            'status' => $data['is_draft'] ?? false ? 'draft' : 'pending',
            'total_amount' => $vatCalculation['total_amount'],
            'net_amount' => $vatCalculation['net_amount'],
            'vat_amount' => $vatCalculation['vat_amount'],
            'payment_method' => $data['payment_method'],
            'supplier_id' => $data['supplier_id'] ?? null,
        ]);

        // Create quick cash expense details
        $quickCash = QuickCashExpense::create([
            'expense_id' => $expense->id,
            'expense_date' => $data['expense_date'],
            'expense_name' => $data['expense_name'],
            'has_vat' => $data['has_vat'] ?? false,
            'invoice_number' => $data['invoice_number'] ?? null,
        ]);

        // Create quick cash items
        foreach ($data['items'] as $item) {
            QuickCashItem::create([
                'quick_cash_expense_id' => $quickCash->id,
                'title' => $item['title'],
                'amount' => $item['amount'],
            ]);
        }

        // Upload invoice receipt if provided
        if (isset($data['invoice_receipt'])) {
            $this->uploadInvoiceReceipt($expense, $data['invoice_receipt']);
        }

        // Create timeline entry
        $this->createTimelineEntry($expense, 'created', $data['is_draft'] ?? false ? 'saved_as_draft' : 'submitted');

        return $expense;
    }

    /**
     * Update Quick Cash Expense
     */
    public function updateQuickCashExpense(Expense $expense, array $data): Expense
    {
        // Calculate VAT if amount changed
        if (isset($data['total_amount'])) {
            $vatCalculation = $this->calculateVAT(
                $data['total_amount'],
                $data['has_vat'] ?? $expense->quickCashExpense->has_vat
            );

            $expense->update([
                'total_amount' => $vatCalculation['total_amount'],
                'net_amount' => $vatCalculation['net_amount'],
                'vat_amount' => $vatCalculation['vat_amount'],
            ]);
        }

        // Update payment method if changed
        if (isset($data['payment_method'])) {
            $expense->update([
                'payment_method' => $data['payment_method'],
                'supplier_id' => $data['supplier_id'] ?? null,
            ]);
        }

        // Update quick cash expense details
        $expense->quickCashExpense->update(array_filter([
            'expense_date' => $data['expense_date'] ?? null,
            'expense_name' => $data['expense_name'] ?? null,
            'has_vat' => $data['has_vat'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
        ]));

        // Update items if provided
        if (isset($data['items'])) {
            // Delete old items
            $expense->quickCashExpense->items()->delete();

            // Create new items
            foreach ($data['items'] as $item) {
                QuickCashItem::create([
                    'quick_cash_expense_id' => $expense->quickCashExpense->id,
                    'title' => $item['title'],
                    'amount' => $item['amount'],
                ]);
            }
        }

        // Upload new invoice receipt if provided
        if (isset($data['invoice_receipt'])) {
            $this->uploadInvoiceReceipt($expense, $data['invoice_receipt']);
        }

        // Create timeline entry
        $this->createTimelineEntry($expense, 'updated');

        return $expense;
    }

    /**
     * Calculate VAT (15%)
     */
    public function calculateVAT(float $totalAmount, bool $hasVat = true): array
    {
        if (!$hasVat) {
            return [
                'total_amount' => $totalAmount,
                'net_amount' => $totalAmount,
                'vat_amount' => 0,
            ];
        }

        $vatAmount = $totalAmount * 0.15;
        $netAmount = $totalAmount - $vatAmount;

        return [
            'total_amount' => round($totalAmount, 2),
            'net_amount' => round($netAmount, 2),
            'vat_amount' => round($vatAmount, 2),
        ];
    }

    /**
     * Get custody balance for branch manager
     */
    public function getCustodyBalance(int $branchManagerId): float
    {
        // TODO: Implement custody balance logic
        // This should be connected to Custody Management Module
        return 10000.00; // Placeholder
    }

    /**
     * Upload invoice receipt
     */
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

    /**
     * Create timeline entry
     */
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
