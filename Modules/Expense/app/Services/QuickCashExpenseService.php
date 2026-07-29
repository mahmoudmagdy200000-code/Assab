<?php

namespace Modules\Expense\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\QuickCashExpense;
use Modules\Expense\Models\QuickCashItem;

class QuickCashExpenseService
{
    public function __construct(private SupplierBrandScopeService $supplierScope) {}

    /**
     * Create Quick Cash Expense
     */
    public function createQuickCashExpense(array $data): Expense
    {
        $this->supplierScope->assertPayloadVisible(auth()->user()?->branch_id, $data);

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
            'payment_supplier_id' => $data['payment_supplier_id'] ?? null,
        ]);

        if (! empty($data['items']) && is_array($data['items'])) {
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

        return $expense->load(['quickCashExpense.paymentSupplier']);
    }

    /**
     * Update Quick Cash Expense
     */
    public function updateQuickCashExpense(Expense $expense, array $data): Expense
    {
        $this->supplierScope->assertPayloadVisible(auth()->user()?->branch_id, $data);

        // Prepare data for VAT calculation
        $calculationData = array_merge($expense->toArray(), $data);
        $vatCalculation = $this->calculateVAT($calculationData);

        $netAmount = $data['net_amount'] ?? $vatCalculation['net_amount'];
        $vatAmount = $data['vat_amount'] ?? $vatCalculation['vat_amount'];
        $totalAmount = $data['total_amount'] ?? $expense->total_amount;

        // Validate total = net + VAT
        if (abs(($netAmount + $vatAmount) - $totalAmount) > 0.01) {
            throw new \Exception('Total amount must equal net + VAT');
        }

        // Update main expense record
        $expenseUpdateData = [];

        if (isset($data['total_amount'])) {
            $expenseUpdateData['total_amount'] = round($totalAmount, 2);
        }

        $expenseUpdateData['net_amount'] = round($netAmount, 2);
        $expenseUpdateData['vat_amount'] = round($vatAmount, 2);

        if (isset($data['payment_method'])) {
            $expenseUpdateData['payment_method'] = $data['payment_method'];
        }

        if (isset($data['supplier_id'])) {
            $expenseUpdateData['supplier_id'] = $data['supplier_id'];
        }

        if (isset($data['is_draft'])) {

            $expenseUpdateData['status'] = $data['is_draft'] ? 'draft' : 'pending';

            if (! $data['is_draft'] && ! $expense->submitted_at) {
                $expenseUpdateData['submitted_at'] = now();
            }
        }

        $expense->update($expenseUpdateData);

        // Update quick cash expense details
        $quickCashUpdateData = array_filter([
            'expense_date' => $data['expense_date'] ?? null,
            'expense_name' => $data['expense_name'] ?? null,
            'has_vat' => $data['has_vat'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
            'payment_supplier_id' => $data['payment_supplier_id'] ?? null,
        ], function ($value) {
            return $value !== null;
        });

        $quickCashUpdateData['vat_total_amount'] = round($vatCalculation['total_amount'], 2);

        $expense->quickCashExpense->update($quickCashUpdateData);

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

        return $expense->load(['quickCashExpense.paymentSupplier']);
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
                Log::warning('Failed to delete attachment: '.$e->getMessage());
            }
        }
    }

    /**
     * Flexible VAT Calculation
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

        if (! empty($data['vat_total_amount'])) {
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
        try {
            $custodyBalanceService = app(\Modules\Custody\Services\CustodyBalanceService::class);

            return $custodyBalanceService->getCustodyBalance($branchManagerId);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to get custody balance', [
                'branch_manager_id' => $branchManagerId,
                'error' => $e->getMessage(),
            ]);

            return 0.00;
        }
    }

    /**
     * Upload invoice receipt (adds new without deleting old)
     */
    private function uploadInvoiceReceipt(Expense $expense, $file): void
    {
        $filename = 'expense_'.$expense->id.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('expenses/receipts', $filename, 'public');

        $expense->attachments()->create([
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
        ]);
    }

    private function createTimelineEntry(Expense $expense, string $action, ?string $status = null): void
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
