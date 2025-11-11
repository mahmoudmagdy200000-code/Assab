<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\{Expense, QuickCashExpense, QuickCashItem};
use Illuminate\Support\Facades\Storage;

class QuickCashExpenseService
{
    /**
     * Create Quick Cash Expense
     */
    public function createQuickCashExpense(array $data): Expense
    {
        $vatCalculation = $this->calculateVAT($data);
        $netAmount = $data['net_amount'] ?? $vatCalculation['net_amount'];

        if (isset($data['vat_amount']) && abs(($netAmount + $data['vat_amount']) - $data['total_amount']) > 0.01) {
            throw new \Exception('Total amount must equal net + VAT');
        }

        $expense = Expense::create([
            'branch_manager_id' => auth()->id(),
            'expense_type' => 'quick_cash',
            'status' => $data['is_draft'] ?? false ? 'draft' : 'pending',
            'total_amount' => round($data['total_amount'], 2),
            'net_amount' => round($netAmount, 2),
            'vat_amount' => round($data['vat_amount'] ?? $vatCalculation['vat_amount'], 2),
            'payment_method' => $data['payment_method'],
            'supplier_id' => $data['supplier_id'] ?? null,
        ]);

        $quickCash = QuickCashExpense::create([
            'expense_id' => $expense->id,
            'expense_date' => $data['expense_date'],
            'expense_name' => $data['expense_name'],
            'has_vat' => $data['has_vat'] ?? false,
            'vat_total_amount' => round($vatCalculation['total_amount'], 2),
            'invoice_number' => $data['invoice_number'] ?? null,
        ]);

        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                QuickCashItem::create([
                    'quick_cash_expense_id' => $quickCash->id,
                    'title' => $item['title'],
                    'amount' => $item['amount'],
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
     * Update Quick Cash Expense
     */
    public function updateQuickCashExpense(Expense $expense, array $data): Expense
    {
        $vatCalculation = $this->calculateVAT($data);

        $netAmount = $data['net_amount'] ?? $vatCalculation['net_amount'];
        $vatAmount = $data['vat_amount'] ?? $vatCalculation['vat_amount'];
        $totalAmount = $data['total_amount'] ?? $expense->total_amount;

        if (abs(($netAmount + $vatAmount) - $totalAmount) > 0.01) {
            throw new \Exception('Total amount must equal net + VAT');
        }

        $expense->update([
            'total_amount' => round($totalAmount, 2),
            'net_amount' => round($netAmount, 2),
            'vat_amount' => round($vatAmount, 2),
            'payment_method' => $data['payment_method'] ?? $expense->payment_method,
            'supplier_id' => $data['supplier_id'] ?? $expense->supplier_id,
        ]);

        $expense->quickCashExpense->update(array_filter([
            'expense_date' => $data['expense_date'] ?? null,
            'expense_name' => $data['expense_name'] ?? null,
            'has_vat' => $data['has_vat'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
            'vat_total_amount' => round($vatCalculation['total_amount'], 2),
        ]));

        if (isset($data['items'])) {
            $expense->quickCashExpense->items()->delete();
            foreach ($data['items'] as $item) {
                QuickCashItem::create([
                    'quick_cash_expense_id' => $expense->quickCashExpense->id,
                    'title' => $item['title'],
                    'amount' => $item['amount'],
                ]);
            }
        }

        if (isset($data['invoice_receipt']) && is_array($data['invoice_receipt'])) {
            foreach ($data['invoice_receipt'] as $file) {
                $this->uploadInvoiceReceipt($expense, $file);
            }
        }

        $this->createTimelineEntry($expense, 'updated');
        return $expense;
    }

    /**
     * ✅ Flexible VAT Calculation
     * Accepts: array OR (float $amount, bool $hasVAT)
     */
    public function calculateVAT(array|float $data, bool $hasVAT = true): array
    {
        // If only number given
        if (is_numeric($data)) {
            $totalAmount = (float) $data;
            if ($hasVAT) {
                $vatAmount = $totalAmount * (15 / 115);
                $netAmount = $totalAmount - $vatAmount;
            } else {
                $vatAmount = 0;
                $netAmount = $totalAmount;
            }

            return [
                'total_amount' => round($totalAmount, 2),
                'net_amount' => round($netAmount, 2),
                'vat_amount' => round($vatAmount, 2),
                'vat_total_amount' => round($netAmount + $vatAmount, 2),
            ];
        }

        // If array provided
        $totalAmount = $data['total_amount'] ?? $data['vat_total_amount'] ?? 0;
        $hasVAT = $data['has_vat'] ?? $hasVAT;

        if (!empty($data['vat_total_amount'])) {
            $vatAmount = $data['vat_amount'] ?? 0;
            $netAmount = $data['net_amount'] ?? ($totalAmount - $vatAmount);
        } else {
            if ($hasVAT) {
                $vatAmount = $totalAmount * (15 / 115);
                $netAmount = $totalAmount - $vatAmount;
            } else {
                $vatAmount = 0;
                $netAmount = $totalAmount;
            }
        }

        return [
            'total_amount' => round($totalAmount, 2),
            'net_amount' => round($netAmount, 2),
            'vat_amount' => round($vatAmount, 2),
            'vat_total_amount' => round($netAmount + $vatAmount, 2),
        ];
    }

    public function getCustodyBalance(string $branchManagerId): float
    {
        return 10000.00; // Placeholder - integrate with custody module
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
