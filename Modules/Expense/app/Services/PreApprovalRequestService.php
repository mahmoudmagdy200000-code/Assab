<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\{Expense, PreApprovalRequest, ExpenseItem, ExpenseLine};

/**
 * Pre-Approval Request Service
 */
class PreApprovalRequestService
{
    /**
     * Create Pre-Approval Request
     */
    public function createPreApprovalRequest(array $data): Expense
    {
        // Calculate totals
        $totals = $this->calculateTotals($data);

        // Create main expense record
        $expense = Expense::create([
            'branch_manager_id' => auth()->id(),
            'expense_type' => 'pre_approval',
            'status' => $data['is_draft'] ?? false ? 'draft' : 'pending',
            'total_amount' => $data['estimated_amount'],
            'net_amount' => $data['estimated_amount'],
            'vat_amount' => 0,
            'payment_method' => $data['payment_method'],
            'supplier_id' => $data['supplier_id'] ?? null,
        ]);

        // Create pre-approval request details
        $preApproval = PreApprovalRequest::create([
            'expense_id' => $expense->id,
            'purpose' => $data['purpose'],
            'estimated_amount' => $data['estimated_amount'],
            'priority' => $data['priority'],
        ]);

        // Create items if provided
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                ExpenseItem::create([
                    'expense_id' => $expense->id,
                    'category_id' => $item['category_id'],
                    'name' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['rate'],
                    'total_amount' => $item['quantity'] * $item['rate'],
                ]);
            }
        }

        // Create expenses if provided
        if (!empty($data['expenses'])) {
            foreach ($data['expenses'] as $expenseLine) {
                ExpenseLine::create([
                    'expense_id' => $expense->id,
                    'category_id' => $expenseLine['category_id'],
                    'name' => $expenseLine['description'],
                    'price' => $expenseLine['price'],
                ]);
            }
        }

        // Upload attachment if provided
        if (isset($data['attachment'])) {
            $this->uploadAttachment($expense, $data['attachment']);
        }

        // Create timeline entry
        $this->createTimelineEntry($expense, 'created', $data['is_draft'] ?? false ? 'saved_as_draft' : 'submitted');

        return $expense;
    }

    /**
     * Update Pre-Approval Request
     */
    public function updatePreApprovalRequest(Expense $expense, array $data): Expense
    {
        // Update main expense
        $expense->update(array_filter([
            'total_amount' => $data['estimated_amount'] ?? null,
            'net_amount' => $data['estimated_amount'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
        ]));

        // Update pre-approval request details
        $expense->preApprovalRequest->update(array_filter([
            'purpose' => $data['purpose'] ?? null,
            'estimated_amount' => $data['estimated_amount'] ?? null,
            'priority' => $data['priority'] ?? null,
        ]));

        // Update items if provided
        if (isset($data['items'])) {
            $expense->items()->delete();

            foreach ($data['items'] as $item) {
                ExpenseItem::create([
                    'expense_id' => $expense->id,
                    'category_id' => $item['category_id'],
                    'name' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['rate'],
                    'total_amount' => $item['quantity'] * $item['rate'],
                ]);
            }
        }

        // Update expense lines if provided
        if (isset($data['expenses'])) {
            $expense->expenseLines()->delete();

            foreach ($data['expenses'] as $expenseLine) {
                ExpenseLine::create([
                    'expense_id' => $expense->id,
                    'category_id' => $expenseLine['category_id'],
                    'name' => $expenseLine['description'],
                    'price' => $expenseLine['price'],
                ]);
            }
        }

        // Upload new attachment if provided
        if (isset($data['attachment'])) {
            $this->uploadAttachment($expense, $data['attachment']);
        }

        // Create timeline entry
        $this->createTimelineEntry($expense, 'updated');

        return $expense;
    }

    private function calculateTotals(array $data): array
    {
        $itemsTotal = 0;
        $expensesTotal = 0;

        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $itemsTotal += $item['quantity'] * $item['rate'];
            }
        }

        if (!empty($data['expenses'])) {
            foreach ($data['expenses'] as $expense) {
                $expensesTotal += $expense['price'];
            }
        }

        return [
            'items_total' => $itemsTotal,
            'expenses_total' => $expensesTotal,
            'total' => $itemsTotal + $expensesTotal,
        ];
    }

    private function uploadAttachment(Expense $expense, $file): void
    {
        $filename = 'expense_' . $expense->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('expenses/attachments', $filename, 'public');

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
