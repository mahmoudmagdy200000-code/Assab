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
            'payment_supplier' => $this->getPaymentSupplier(),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'approval' => $this->getApprovalFragment(),
            'cancellation' => $this->getCancellationFragment(),
            'timelines' => $this->whenLoaded('timelines', fn() => \App\Http\Resources\UnifiedTimelineResource::collection($this->timelines)),
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
                'payment_supplier' => $this->when($quickCash->payment_supplier_id, [
                    'id' => $quickCash->paymentSupplier?->id,
                    'name' => $quickCash->paymentSupplier?->name,
                ]),
                'items' => $quickCash->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'category_id' => $item->category_id,
                        'category' => $item->category?->name,
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
                'payment_supplier' => $this->when($invoice->payment_supplier_id, [
                    'id' => $invoice->paymentSupplier?->id,
                    'name' => $invoice->paymentSupplier?->name,
                ]),
                'items' => $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'category' => $item->category?->name,
                        'category_id' => $item->category_id,
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
                        'category_id' => $line->category_id,
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
                'payment_supplier' => $this->when($grouped->payment_supplier_id, [
                    'id' => $grouped->paymentSupplier?->id,
                    'name' => $grouped->paymentSupplier?->name,
                ]),
                'paid_amount' => (float) $grouped->paid_amount,
                'due_date' => $grouped->due_date?->format('Y-m-d'),
                'total_invoices' => $grouped->invoiceDetails->count(),
                'default_supplier_id' => $grouped->default_supplier_id ?? null,
                'default_supplier_name' => $grouped->defaultSupplier?->name,
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
                        'invoice_supplier_name' => $invoice->supplier?->name,
                        'net_amount' => $invoice->tax_net_amount !== null ? (float) $invoice->tax_net_amount : null,
                        'vat_amount' => $invoice->tax_vat_amount !== null ? (float) $invoice->tax_vat_amount : null,
                        'total_amount' => $invoice->tax_total_amount !== null ? (float) $invoice->tax_total_amount : null,
                        'items_count' => $invoice->items->count(),
                        'expenses_count' => $invoice->expenseLines->count(),
                        'items' => $invoice->items->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'category' => $item->category?->name,
                                'category_id' => $item->category_id,
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
                                'category_id' => $line->category_id,
                                'name' => $line->name,
                                'price' => (float) $line->price,
                            ];
                        }),
                        'receipts' => $this->getInvoiceReceipts($invoice->id),

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
                'payment_supplier' => $this->when($preApproval->payment_supplier_id, [
                    'id' => $preApproval->paymentSupplier?->id,
                    'name' => $preApproval->paymentSupplier?->name,
                ]),
                'items' => $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'category' => $item->category?->name,
                        'category_id' => $item->category_id,
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
                        'category_id' => $line->category_id,
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

    private function getInvoiceReceipts(string $invoiceId): array
    {
        $attachments = $this->attachments->where('invoice_detail_id', $invoiceId);

        if ($attachments->isEmpty()) {
            return [];
        }

        return $attachments->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'file_name' => $attachment->file_name,
                'file_type' => $attachment->file_type,
                'file_size' => $attachment->file_size,
                'url' => asset('storage/' . $attachment->file_path),
                'uploaded_at' => $attachment->created_at->format('Y-m-d H:i:s'),
            ];
        })->values()->toArray();
    }

    private function getApprovalFragment(): array
    {
        return [
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
            'rejection_reason' => $this->rejection_reason,
        ];
    }

    private function getCancellationFragment(): ?array
    {
        if ($this->status !== 'rejected' || !$this->rejected_by) {
            return null;
        }

        $brandOwner = \Modules\BrandOwner\Models\BrandOwner::find($this->rejected_by);

        return [
            'cancellation_reason' => $this->rejection_reason,
            'cancelled_at'        => $this->rejected_at?->toIso8601String(),
            'cancelled_by'        => [
                'id'   => $this->rejected_by,
                'name' => $brandOwner?->name,
                'type' => 'brand_owner',
            ],
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

    /**
     * Get payment supplier based on expense type
     */
    private function getPaymentSupplier(): ?array
    {
        return match ($this->expense_type) {
            'quick_cash' => $this->quickCashExpense?->payment_supplier_id ? [
                'id' => $this->quickCashExpense->paymentSupplier?->id,
                'name' => $this->quickCashExpense->paymentSupplier?->name,
            ] : null,
            'single_invoice' => $this->invoiceDetails->first()?->payment_supplier_id ? [
                'id' => $this->invoiceDetails->first()->paymentSupplier?->id,
                'name' => $this->invoiceDetails->first()->paymentSupplier?->name,
            ] : null,
            'grouped_invoice' => $this->groupedInvoice?->payment_supplier_id ? [
                'id' => $this->groupedInvoice->paymentSupplier?->id,
                'name' => $this->groupedInvoice->paymentSupplier?->name,
            ] : null,
            'pre_approval' => $this->preApprovalRequest?->payment_supplier_id ? [
                'id' => $this->preApprovalRequest->paymentSupplier?->id,
                'name' => $this->preApprovalRequest->paymentSupplier?->name,
            ] : null,
            default => null,
        };
    }
}
