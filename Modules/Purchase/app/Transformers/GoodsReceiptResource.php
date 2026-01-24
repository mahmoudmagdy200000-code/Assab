<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'status' => $this->status,
            'document_type' => $this->document_type?->value,
            
            // Purchase Order
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn() => new PurchaseOrderResource($this->purchaseOrder)),
            
            // Delivery details
            'delivery_details' => [
                'driver_name' => $this->driver_name,
                'driver_contact' => $this->driver_contact,
                'driver_image' => $this->driver_image,
                'vehicle_number' => $this->vehicle_number,
                'arrival_time' => $this->arrival_time?->format('Y-m-d H:i:s'),
                'delivery_address' => $this->delivery_address,
                'delivery_notes' => $this->delivery_notes,
            ],
            
            // Inspection summary
            'inspection_summary' => [
                'total_items_expected' => $this->total_items_expected,
                'total_items_received' => $this->total_items_received,
                'quantity_variances' => $this->quantity_variances,
                'quality_variances' => $this->quality_variances,
                'has_variances' => $this->has_variances,
            ],
            
            // Financial summary (basic amounts from receipt)
            'financial_summary' => array_merge(
                [
                    'expected_amount' => (float) $this->expected_amount,
                    'received_amount' => (float) $this->received_amount,
                    'variance_amount' => (float) $this->variance_amount,
                ],
                // Add invoice financial details if document_type is invoice and invoice exists
                $this->document_type?->requiresInvoiceDetails() && $this->invoice
                    ? [
                        'amount_before_tax' => (float) $this->invoice->amount_before_tax,
                        'vat_rate' => (float) $this->invoice->tax_rate,
                        'vat_amount' => (float) $this->invoice->tax_amount,
                        'total_amount' => (float) $this->invoice->total_amount,
                    ]
                    : []
            ),
            
            // Timestamps
            'inspection_started_at' => $this->inspection_started_at?->format('Y-m-d H:i:s'),
            'inspection_completed_at' => $this->inspection_completed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            
            // Flags
            'is_draft' => $this->is_draft,
            'is_completed' => $this->is_completed,
            
            // Related data
            'items' => GoodsReceiptItemResource::collection($this->whenLoaded('items')),
            'invoice' => $this->whenLoaded('invoice', fn() => new InvoiceResource($this->invoice)),
            'variances' => VarianceResource::collection($this->whenLoaded('variances')),
            
            // Document summary
            'document_summary' => [
                'document_type' => $this->document_type?->value,
                'invoice_details' => $this->when(
                    $this->document_type?->requiresInvoiceDetails() && $this->invoice,
                    fn() => [
                        'invoice_number' => $this->invoice->invoice_number,
                        'invoice_date' => $this->invoice->invoice_date?->format('Y-m-d'),
                        'supplier_name' => $this->purchaseOrder->supplier?->name,
                        'attachment' => $this->invoice->file_url,
                    ]
                ),
            ],
            
            // Variance summary with actions
            'variance_summary' => $this->whenLoaded('variances', function () {
                return $this->variances->map(function ($variance) {
                    return [
                        'item_name' => $variance->item_name,
                        'variance_type' => $variance->variance_type?->value,
                        'amount_variance' => (float) $variance->variance_amount,
                        'required_action' => $variance->action?->value,
                        'amount_to_deduct' => $variance->amount_to_deduct ? (float) $variance->amount_to_deduct : null,
                        'reason_for_deduction' => $variance->deduction_reason,
                        'additional_note' => $variance->additional_notes,
                    ];
                });
            }),

            // Supplier (from order, same shape as Return details)
            'supplier' => $this->whenLoaded('purchaseOrder', function () {
                $order = $this->purchaseOrder;
                if (!$order?->relationLoaded('supplier') || !$order->supplier) {
                    return null;
                }
                $s = $order->supplier;
                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'image' => $s->image_url ?? null,
                    'status' => $s->status ?? 'offline',
                    'status_label' => $s->status_label ?? 'Offline',
                    'contact_methods' => $s->contact_methods ?? [],
                    'average_response_time_hours' => $s->average_response_time_hours !== null
                        ? (float) $s->average_response_time_hours
                        : null,
                    'response_rate_percentage' => $s->response_rate_percentage !== null
                        ? (float) $s->response_rate_percentage
                        : null,
                ];
            }),

            // Timelines
            'timelines' => $this->whenLoaded('timelines', fn () => TimelineResource::collection($this->timelines)),
        ];
    }
}

