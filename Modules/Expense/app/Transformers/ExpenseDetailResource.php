<?php

namespace Modules\Expense\Transformers;

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
            // Every string below is emitted as a STRING, never null: the app
            // casts these fields with `as String` and a null crashes the whole
            // screen with «type 'Null' is not a subtype of type 'String'»
            // («الطلبات السابقة» for single invoices, 2026-08-04). Same rule the
            // supplier resources already follow for null numerics.
            'payment_method' => (string) ($this->payment_method ?? ''),
            'supplier' => $this->when($this->supplier_id, [
                'id' => (string) ($this->supplier?->id ?? ''),
                'name' => (string) ($this->supplier?->name ?? ''),
            ]),
            'payment_supplier' => $this->getPaymentSupplier(),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s') ?? '',
            // A draft has no submitted_at; the list still renders a date for it.
            'submitted_at' => ($this->submitted_at ?? $this->created_at)?->format('Y-m-d H:i:s') ?? '',
            'approval' => $this->getApprovalFragment(),
            'cancellation' => $this->getCancellationFragment(),
            'timelines' => $this->whenLoaded('timelines', fn () => \App\Http\Resources\UnifiedTimelineResource::collection($this->timelines)),
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

        if ($quickCash === null) {
            return [
                'data' => [
                    'expense_date' => $this->created_at?->format('Y-m-d') ?? '',
                    'expense_name' => '', 'has_vat' => false, 'vat_total_amount' => 0.0,
                    'invoice_number' => '', 'items' => [],
                ],
                'attachments' => $this->getAttachments(),
            ];
        }

        return [
            'data' => [
                'expense_date' => ($quickCash->expense_date ?? $this->created_at)?->format('Y-m-d') ?? '',
                'expense_name' => (string) ($quickCash->expense_name ?? ''),
                'has_vat' => (bool) $quickCash->has_vat,
                'vat_total_amount' => (float) $quickCash->vat_total_amount,
                'invoice_number' => (string) ($quickCash->invoice_number ?? ''),
                'payment_supplier' => $this->when($quickCash->payment_supplier_id, [
                    'id' => (string) ($quickCash->paymentSupplier?->id ?? ''),
                    'name' => (string) ($quickCash->paymentSupplier?->name ?? ''),
                ]),
                'items' => $quickCash->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'category_id' => $item->category_id,
                        'category' => (string) ($item->category?->name ?? ''),
                        'title' => (string) ($item->title ?? ''),
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

        // A single-invoice expense whose detail row was never written (an
        // abandoned draft) used to blow up on `$invoice->invoice_number` — a 500
        // for the WHOLE list because of one bad row.
        if ($invoice === null) {
            return [
                'data' => [
                    'invoice_number' => '',
                    'issue_date' => $this->created_at?->format('Y-m-d') ?? '',
                    'is_tax_invoice' => false,
                    'tax_id' => '',
                    'payment_type' => '',
                    'paid_amount' => 0.0,
                    'due_date' => null,
                    'items' => [],
                    'expenses' => [],
                ],
                'attachments' => $this->getAttachments(),
            ];
        }

        $data = [
            'data' => [
                'invoice_number' => (string) ($invoice->invoice_number ?? ''),
                'issue_date' => ($invoice->issue_date ?? $this->created_at)?->format('Y-m-d') ?? '',
                'is_tax_invoice' => (bool) $invoice->is_tax_invoice,
                'tax_id' => (string) ($invoice->tax_id ?? ''),
                'payment_type' => (string) ($invoice->payment_type ?? ''),
                'paid_amount' => (float) $invoice->paid_amount,
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'payment_supplier' => $this->when($invoice->payment_supplier_id, [
                    'id' => (string) ($invoice->paymentSupplier?->id ?? ''),
                    'name' => (string) ($invoice->paymentSupplier?->name ?? ''),
                ]),
                'items' => $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'category' => (string) ($item->category?->name ?? ''),
                        'category_id' => $item->category_id,
                        'name' => (string) ($item->name ?? ''),
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'total_amount' => (float) $item->total_amount,
                    ];
                }),
                'expenses' => $this->expenseLines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'category' => (string) ($line->category?->name ?? ''),
                        'category_id' => $line->category_id,
                        'name' => (string) ($line->name ?? ''),
                        'price' => (float) $line->price,
                    ];
                }),
            ],
            'attachments' => $this->getAttachments(),
        ];

        // Tax Invoice
        if ($invoice->is_tax_invoice) {
            $data['single_invoice']['tax_invoice_details'] = [
                'supplier_name' => (string) ($invoice->supplier?->name ?? ''),
                'net_amount' => (float) $invoice->tax_net_amount,
                'vat_amount' => (float) $invoice->tax_vat_amount,
                'total_amount' => (float) $invoice->tax_total_amount,
            ];
        }

        return $data;
    }

    private function getGroupedInvoiceDetails(): array
    {
        $grouped = $this->groupedInvoice;

        if ($grouped === null) {
            return [
                'data' => [
                    'number_of_suppliers' => 0, 'supplier_names' => [], 'payment_type' => '',
                    'paid_amount' => 0.0, 'due_date' => null, 'total_invoices' => 0,
                    'default_supplier_id' => null, 'default_supplier_name' => '', 'invoices' => [],
                ],
            ];
        }

        return [
            'data' => [
                'number_of_suppliers' => $grouped->invoiceDetails->pluck('supplier_id')->unique()->count(),
                // A grouped invoice whose supplier row was deleted used to throw
                // on `->supplier->name` and take the whole list with it.
                'supplier_names' => $grouped->invoiceDetails
                    ->map(fn ($inv) => (string) ($inv->supplier?->name ?? ''))
                    ->filter()->unique()->values(),
                'payment_type' => (string) ($grouped->payment_type ?? ''),
                'payment_supplier' => $this->when($grouped->payment_supplier_id, [
                    'id' => $grouped->paymentSupplier?->id,
                    'name' => $grouped->paymentSupplier?->name,
                ]),
                'paid_amount' => (float) $grouped->paid_amount,
                'due_date' => $grouped->due_date?->format('Y-m-d'),
                'total_invoices' => $grouped->invoiceDetails->count(),
                'default_supplier_id' => $grouped->default_supplier_id ?? null,
                'default_supplier_name' => (string) ($grouped->defaultSupplier?->name ?? ''),
                'invoices' => $grouped->invoiceDetails->map(function ($invoice) {
                    return [
                        'id' => $invoice->id,
                        'supplier' => [
                            'id' => (string) ($invoice->supplier?->id ?? ''),
                            'name' => (string) ($invoice->supplier?->name ?? ''),
                        ],
                        'invoice_number' => (string) ($invoice->invoice_number ?? ''),
                        'issue_date' => $invoice->issue_date?->format('Y-m-d') ?? '',
                        'is_tax_invoice' => (bool) $invoice->is_tax_invoice,
                        'tax_id' => (string) ($invoice->tax_id ?? ''),
                        'invoice_supplier_name' => (string) ($invoice->supplier?->name ?? ''),
                        'net_amount' => $invoice->tax_net_amount !== null ? (float) $invoice->tax_net_amount : null,
                        'vat_amount' => $invoice->tax_vat_amount !== null ? (float) $invoice->tax_vat_amount : null,
                        'total_amount' => $invoice->tax_total_amount !== null ? (float) $invoice->tax_total_amount : null,
                        'items_count' => $invoice->items->count(),
                        'expenses_count' => $invoice->expenseLines->count(),
                        'items' => $invoice->items->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'category' => (string) ($item->category?->name ?? ''),
                                'category_id' => $item->category_id,
                                'name' => (string) ($item->name ?? ''),
                                'quantity' => (float) $item->quantity,
                                'unit_price' => (float) $item->unit_price,
                                'total_amount' => (float) $item->total_amount,
                            ];
                        }),
                        'expenses' => $invoice->expenseLines->map(function ($line) {
                            return [
                                'id' => $line->id,
                                'category' => (string) ($line->category?->name ?? ''),
                                'category_id' => $line->category_id,
                                'name' => (string) ($line->name ?? ''),
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

        // Same rule as getSingleInvoiceDetails: the «الطلبات السابقة» list must
        // survive a row whose detail record is missing, and every string it
        // emits is a string.
        if ($preApproval === null) {
            return [
                'data' => [
                    'purpose' => '',
                    'estimated_amount' => 0.0,
                    'priority' => ['value' => '', 'label' => ''],
                    'items' => [],
                    'expenses' => [],
                ],
                'attachments' => $this->getAttachments(),
            ];
        }

        return [
            'data' => [
                'purpose' => (string) ($preApproval->purpose ?? ''),
                'estimated_amount' => (float) $preApproval->estimated_amount,
                'priority' => [
                    'value' => (string) ($preApproval->priority ?? ''),
                    'label' => ucfirst((string) ($preApproval->priority ?? '')),
                ],
                'payment_supplier' => $this->when($preApproval->payment_supplier_id, [
                    'id' => (string) ($preApproval->paymentSupplier?->id ?? ''),
                    'name' => (string) ($preApproval->paymentSupplier?->name ?? ''),
                ]),
                'items' => $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'category' => (string) ($item->category?->name ?? ''),
                        'category_id' => $item->category_id,
                        'description' => (string) ($item->name ?? ''),
                        'quantity' => (float) $item->quantity,
                        'rate' => (float) $item->unit_price,
                        'total' => (float) $item->total_amount,
                    ];
                }),
                'expenses' => $this->expenseLines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'category' => (string) ($line->category?->name ?? ''),
                        'category_id' => $line->category_id,
                        'description' => (string) ($line->name ?? ''),
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
                'file_name' => (string) ($attachment->file_name ?? ''),
                'file_type' => (string) ($attachment->file_type ?? ''),
                'file_size' => $attachment->file_size,
                'url' => asset('storage/'.$attachment->file_path),
                'uploaded_at' => $attachment->created_at?->format('Y-m-d H:i:s') ?? '',
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
                'file_name' => (string) ($attachment->file_name ?? ''),
                'file_type' => (string) ($attachment->file_type ?? ''),
                'file_size' => $attachment->file_size,
                'url' => asset('storage/'.$attachment->file_path),
                'uploaded_at' => $attachment->created_at?->format('Y-m-d H:i:s') ?? '',
            ];
        })->values()->toArray();
    }

    private function getApprovalFragment(): ?array
    {
        $actorId = $this->approved_by ?? $this->rejected_by;
        if (! $actorId) {
            return null;
        }

        $brandOwner = \Modules\BrandOwner\Models\BrandOwner::find($actorId);

        return [
            'id' => $actorId,
            'name' => $brandOwner?->name ?? 'Brand Owner',
            'role' => 'brand_owner',
            'imageUrl' => $brandOwner?->image_url,
            'status' => $this->approved_by ? 'approved' : 'rejected',
        ];
    }

    private function getCancellationFragment(): ?array
    {
        if ($this->status !== 'rejected' || ! $this->rejected_by) {
            return null;
        }

        $brandOwner = \Modules\BrandOwner\Models\BrandOwner::find($this->rejected_by);

        return [
            'cancellation_reason' => $this->rejection_reason,
            'cancelled_at' => $this->rejected_at?->toIso8601String(),
            'cancelled_by' => [
                'id' => $this->rejected_by,
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
        // A supplier row deleted after the expense was filed leaves the id set
        // and the relation null — the block must still carry strings.
        $pair = fn (?object $supplier) => [
            'id' => (string) ($supplier?->id ?? ''),
            'name' => (string) ($supplier?->name ?? ''),
        ];

        return match ($this->expense_type) {
            'quick_cash' => $this->quickCashExpense?->payment_supplier_id
                ? $pair($this->quickCashExpense->paymentSupplier) : null,
            'single_invoice' => $this->invoiceDetails->first()?->payment_supplier_id
                ? $pair($this->invoiceDetails->first()->paymentSupplier) : null,
            'grouped_invoice' => $this->groupedInvoice?->payment_supplier_id
                ? $pair($this->groupedInvoice->paymentSupplier) : null,
            'pre_approval' => $this->preApprovalRequest?->payment_supplier_id
                ? $pair($this->preApprovalRequest->paymentSupplier) : null,
            default => null,
        };
    }
}
