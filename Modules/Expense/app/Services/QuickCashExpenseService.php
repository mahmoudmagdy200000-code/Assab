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
        // Calculate VAT - Support manual VAT input
        $vatCalculation = $this->calculateVAT($data);


        $netAmount = $data['net_amount'] ?? $vatCalculation['net_amount'];

        // تحقق من التناسق بين total و net و vat
        if (isset($data['vat_amount']) && abs(($netAmount + $data['vat_amount']) - $data['total_amount']) > 0.01) {
            throw new \Exception('Total amount must equal net + VAT');
        }

        // Create main expense record
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


        // Create quick cash expense details
        $quickCash = QuickCashExpense::create([
            'expense_id' => $expense->id,
            'expense_date' => $data['expense_date'],
            'expense_name' => $data['expense_name'],
            'has_vat' => $data['has_vat'] ?? false,
            'vat_total_amount' => round($vatCalculation['total_amount'], 2), // ✅
            'invoice_number' => $data['invoice_number'] ?? null,
        ]);


        // Create quick cash items if provided
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                QuickCashItem::create([
                    'quick_cash_expense_id' => $quickCash->id,
                    'title' => $item['title'],
                    'amount' => $item['amount'],
                ]);
            }
        }

        // Upload invoice receipts if provided
        if (isset($data['invoice_receipt']) && is_array($data['invoice_receipt'])) {
            foreach ($data['invoice_receipt'] as $file) {
                $this->uploadInvoiceReceipt($expense, $file);
            }
        }

        // Create timeline entry
        $this->createTimelineEntry(
            $expense,
            'created',
            $data['is_draft'] ?? false ? 'saved_as_draft' : 'submitted'
        );

        return $expense;
    }

    /**
     * Update Quick Cash Expense
     */
    public function updateQuickCashExpense(Expense $expense, array $data): Expense
    {
        // Calculate VAT if amount changed
        if (isset($data['total_amount']) || isset($data['vat_amount']) || isset($data['net_amount'])) {
            $vatCalculation = $this->calculateVAT(
                $data['total_amount'] ?? $expense->total_amount,
                $data['has_vat'] ?? $expense->quickCashExpense->has_vat,
                $data['vat_amount'] ?? null
            );


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
            'vat_total_amount' => isset($vatCalculation)
                ? round($vatCalculation['total_amount'], 2)
                : $expense->quickCashExpense->vat_total_amount,
        ]));


        // Update items if provided
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

        // Upload new invoice receipt if provided
        if (isset($data['invoice_receipt']) && is_array($data['invoice_receipt'])) {
            foreach ($data['invoice_receipt'] as $file) {
                $this->uploadInvoiceReceipt($expense, $file);
            }
        }

        $this->createTimelineEntry($expense, 'updated');

        return $expense;
    }

    /**
     * Calculate VAT (15% default or manual input)
     */
    public function calculateVAT(array $data): array
    {
        //
        if (isset($data['vat_total_amount'])) {
            $totalAmount = $data['vat_total_amount'];
            $vatAmount = $data['vat_amount'] ?? 0;
            $netAmount = $data['net_amount'] ?? ($totalAmount - $vatAmount);

            return [
                'total_amount' => round($totalAmount, 2),
                'net_amount' => round($netAmount, 2),
                'vat_amount' => round($vatAmount, 2),
                'vat_total_amount' => round($totalAmount, 2),
            ];
        }

        //
        $totalAmount = $data['total_amount'];
        if (!empty($data['has_vat'])) {
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


    public function getCustodyBalance(int $branchManagerId): float
    {
        // TODO: Implement custody balance logic
        // This should be connected to Custody Management Module
        return 10000.00; // Placeholder
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
