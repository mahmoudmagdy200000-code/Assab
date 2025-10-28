<?php

namespace Modules\Expense\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expense Detail Resource
 * For viewing full expense details
 */
class ExpenseDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        $baseData = [
            'id' => $this->id,
            'expense_type' => [
                'value' => $this->expense_type,
                'label' => $this->getExpenseTypeLabel(),
            ],
            'status' => [
                'value' => $this->status,
                'label' => ucfirst($this->status),
                'color' => $this->getStatusColor(),
            ],
            'total_amount' => (float) $this->total_amount,
            'net_amount' => (float) $this->net_amount,
            'vat_amount' => (float) $this->vat_amount,
            'payment_method' => $this->payment_method,
            'supplier' => $this->when($this->supplier_id, [
                'id' => $this->supplier?->id,
                'name' => $this->supplier?->name,
            ]),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
        ];

        // Add type-specific details
        return array_merge($baseData, $this->getTypeSpecificDetails());
    }

    private function getTypeSpecificDetails(): array
    {
        return match ($this->expense_type) {
            'quick_cash' => $this->getQuickCashDetails(),
            'single_invoice' => $this->getSingleInvoiceDetails(),
            'grouped_invoice' => $this->getGroupedInvoiceDetails(),
            'pre_approval' => $this->getPreApprovalDetails(),
            default => [],
        };
    }

    private function getQuickCashDetails(): array
    {
        $quickCash = $this->quickCashExpense;

        return [
            'data' => [
                'expense_date' => $quickCash->expense_date->format('Y-m-d'),
                'expense_name' => $quickCash->expense_name,
                'has_vat' => $quickCash->has_vat,
                'vat_total_amount' => (float) $this->quickCashExpense->vat_total_amount,
                'invoice_number' => $quickCash->invoice_number,
                'items' => $quickCash->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'title' => $item->title,
                        'amount' => (float) $item->amount,
                    ];
                }),
            ],
            'attachments' => $this->getAttachments(),
        ];
    }

    private function getSingleInvoiceDetails(): array
    {
        $invoice = $this->invoiceDetails->first();

        $data = [
            'data' => [
                'invoice_number' => $invoice->invoice_number,
                'issue_date' => $invoice->issue_date->format('Y-m-d'),
                'is_tax_invoice' => $invoice->is_tax_invoice,
                'tax_id' => $invoice->tax_id,
                'payment_type' => $invoice->payment_type,
                'paid_amount' => (float) $invoice->paid_amount,
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'items' => $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'category' => $item->category?->name,
                        'name' => $item->name,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'total_amount' => (float) $item->total_amount,
                    ];
                }),
                'expenses' => $this->expenseLines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'category' => $line->category?->name,
                        'name' => $line->name,
                        'price' => (float) $line->price,
                    ];
                }),
            ],
            'attachments' => $this->getAttachments(),
        ];

        // Tax Invoice
        if ($invoice->is_tax_invoice) {
            $data['single_invoice']['tax_invoice_details'] = [
                'supplier_name' => $invoice->supplier?->name,
                'net_amount'    => (float) $invoice->tax_net_amount,
                'vat_amount'    => (float) $invoice->tax_vat_amount,
                'total_amount'  => (float) $invoice->tax_total_amount,
            ];
        }

        return $data;
    }


    private function getGroupedInvoiceDetails(): array
    {
        $grouped = $this->groupedInvoice;

        return [
            'data' => [
                'number_of_suppliers' => $grouped->invoiceDetails->pluck('supplier_id')->unique()->count(),
                'supplier_names' => $grouped->invoiceDetails->map(fn($inv) => $inv->supplier->name)->unique()->values(),
                'payment_type' => $grouped->payment_type,
                'paid_amount' => (float) $grouped->paid_amount,
                'due_date' => $grouped->due_date?->format('Y-m-d'),
                'total_invoices' => $grouped->invoiceDetails->count(),
                'invoices' => $grouped->invoiceDetails->map(function ($invoice) {
                    return [
                        'id' => $invoice->id,
                        'supplier' => [
                            'id' => $invoice->supplier->id,
                            'name' => $invoice->supplier->name,
                        ],
                        'invoice_number' => $invoice->invoice_number,
                        'issue_date' => $invoice->issue_date->format('Y-m-d'),
                        'is_tax_invoice' => $invoice->is_tax_invoice,
                        'tax_id' => $invoice->tax_id,
                        'items_count' => $invoice->items->count(),
                        'expenses_count' => $invoice->expenseLines->count(),
                        'items' => $invoice->items->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'category' => $item->category?->name,
                                'name' => $item->name,
                                'quantity' => (float) $item->quantity,
                                'unit_price' => (float) $item->unit_price,
                                'total_amount' => (float) $item->total_amount,
                            ];
                        }),
                        'expenses' => $invoice->expenseLines->map(function ($line) {
                            return [
                                'id' => $line->id,
                                'category' => $line->category?->name,
                                'name' => $line->name,
                                'price' => (float) $line->price,
                            ];
                        }),
                        'receipt' => $this->getInvoiceReceipt($invoice->id),
                    ];
                }),
            ],
        ];
    }

    private function getPreApprovalDetails(): array
    {
        $preApproval = $this->preApprovalRequest;

        return [
            'data' => [
                'purpose' => $preApproval->purpose,
                'estimated_amount' => (float) $preApproval->estimated_amount,
                'priority' => [
                    'value' => $preApproval->priority,
                    'label' => ucfirst($preApproval->priority),
                ],
                'items' => $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'category' => $item->category?->name,
                        'description' => $item->name,
                        'quantity' => (float) $item->quantity,
                        'rate' => (float) $item->unit_price,
                        'total' => (float) $item->total_amount,
                    ];
                }),
                'expenses' => $this->expenseLines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'category' => $line->category?->name,
                        'description' => $line->name,
                        'price' => (float) $line->price,
                    ];
                }),
            ],
            'attachments' => $this->getAttachments(),
        ];
    }

    private function getAttachments(): array
    {
        return $this->attachments->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'file_name' => $attachment->file_name,
                'file_type' => $attachment->file_type,
                'file_size' => $attachment->file_size,
                'url' => asset('storage/' . $attachment->file_path),
                'uploaded_at' => $attachment->created_at->format('Y-m-d H:i:s'),
            ];
        })->toArray();
    }

    private function getInvoiceReceipt(int $invoiceId): ?array
    {
        $attachment = $this->attachments->where('invoice_detail_id', $invoiceId)->first();

        if (!$attachment) {
            return null;
        }

        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'url' => asset('storage/' . $attachment->file_path),
        ];
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
