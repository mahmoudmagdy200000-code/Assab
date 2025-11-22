<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\{Expense, PreApprovalRequest, ExpenseItem, ExpenseLine};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

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
        $totals = $this->calculateTotals($data);

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

        $preApproval = PreApprovalRequest::create([
            'expense_id' => $expense->id,
            'purpose' => $data['purpose'],
            'estimated_amount' => $data['estimated_amount'],
            'priority' => $data['priority'],
        ]);

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

        if (!empty($data['attachments'])) {
            foreach ($data['attachments'] as $file) {
                $this->uploadAttachment($expense, $file);
            }
        }

        $this->createTimelineEntry($expense, 'created', $data['is_draft'] ?? false ? 'saved_as_draft' : 'submitted');

        return $expense;
    }

    /**
     * Update Pre-Approval Request
     */
    public function updatePreApprovalRequest(Expense $expense, array $data): Expense
    {
        // Update main expense
        $expenseUpdateData = [];

        if (isset($data['estimated_amount'])) {
            $expenseUpdateData['total_amount'] = $data['estimated_amount'];
            $expenseUpdateData['net_amount'] = $data['estimated_amount'];
        }

        if (isset($data['payment_method'])) {
            $expenseUpdateData['payment_method'] = $data['payment_method'];
        }

        if (isset($data['supplier_id'])) {
            $expenseUpdateData['supplier_id'] = $data['supplier_id'];
        }

        // ✅ إضافة معالجة is_draft
        if (isset($data['is_draft'])) {
            $expenseUpdateData['status'] = $data['is_draft'] ? 'draft' : 'pending';

            if (!$data['is_draft'] && !$expense->submitted_at) {
                $expenseUpdateData['submitted_at'] = now();
            }
        }

        if (!empty($expenseUpdateData)) {
            $expense->update($expenseUpdateData);
        }

        // Update pre-approval request details
        $preApprovalUpdateData = array_filter([
            'purpose' => $data['purpose'] ?? null,
            'estimated_amount' => $data['estimated_amount'] ?? null,
            'priority' => $data['priority'] ?? null,
        ], function ($value) {
            return $value !== null;
        });

        if (!empty($preApprovalUpdateData)) {
            $expense->preApprovalRequest->update($preApprovalUpdateData);
        }

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

        // Delete specific attachments if requested
        if (isset($data['delete_attachments']) && is_array($data['delete_attachments'])) {
            $this->deleteAttachments($expense, $data['delete_attachments']);
        }

        // Upload new attachments WITHOUT deleting old ones
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            foreach ($data['attachments'] as $file) {
                $this->uploadAttachment($expense, $file);
            }
        }

        $action = (isset($data['is_draft']) && !$data['is_draft']) ? 'submitted' : 'updated';
        $this->createTimelineEntry($expense, $action);

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

    /**
     * Upload attachment (adds new without deleting old)
     */
    private function uploadAttachment(Expense $expense, $file): void
    {
        $filename = 'expense_' . $expense->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
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
