<?php

namespace Modules\Expense\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'expense_name' => $this->getExpenseName(),
            'expense_type' => [
                'value' => $this->expense_type,
                'label' => $this->getExpenseTypeLabel(),
            ],
            'amount' => (float) $this->total_amount,
            'date' => $this->getExpenseDate(),
            'time' => $this->getExpenseTime(),
            'status' => [
                'value' => $this->status,
                'label' => ucfirst($this->status),
                'color' => $this->getStatusColor(),
            ],
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }

    private function getExpenseName(): string
    {
        return match ($this->expense_type) {
            'quick_cash' => $this->quickCashExpense?->expense_name ?? 'Quick Cash Expense',
            'single_invoice' => $this->invoiceDetails->first()?->invoice_number ?? 'Single Invoice',
            'grouped_invoice' => 'Grouped Invoices (' . $this->groupedInvoice?->invoiceDetails->count() . ')',
            'pre_approval' => $this->preApprovalRequest?->purpose ?? 'Pre-Approval Request',
            default => 'Expense',
        };
    }

    private function getExpenseTypeLabel(): string
    {
        return match ($this->expense_type) {
            'quick_cash' => 'Quick Cash Expense',
            'single_invoice' => 'Single Invoice',
            'grouped_invoice' => 'Grouped Invoices',
            'pre_approval' => 'Pre-Approval Request',
            default => 'Unknown',
        };
    }

    private function getExpenseDate(): string
    {
        $date = match ($this->expense_type) {
            'quick_cash' => $this->quickCashExpense?->expense_date,
            'single_invoice' => $this->invoiceDetails->first()?->issue_date,
            'grouped_invoice' => $this->groupedInvoice?->invoiceDetails->first()?->issue_date,
            'pre_approval' => $this->created_at,
            default => $this->created_at,
        };

        return $date ? $date->format('Y-m-d') : $this->created_at->format('Y-m-d');
    }

    private function getExpenseTime(): string
    {
        return $this->created_at->format('H:i');
    }

    private function getStatusColor(): string
    {
        return match ($this->status) {
            'draft' => 'gray',
            'pending' => 'yellow',
            'approved' => 'green',
            'rejected' => 'red',
            default => 'gray',
        };
    }
}
