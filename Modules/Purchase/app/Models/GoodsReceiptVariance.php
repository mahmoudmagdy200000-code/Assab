<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptVariance extends Model
{
    use HasUuids;
    protected $fillable = [
        'goods_receipt_id',
        'goods_receipt_item_id',
        'variance_type', // short, damage, over
        'variance_quantity',
        'variance_amount',
        'action_taken', // accept_variance, create_compensatory_order, deduct_from_invoice
        'compensatory_order_id',
        'deduction_amount',
        'deduction_reason',
        'photo_evidence',
        'notes',
        'approved_by_supplier',
        'supplier_response',
        'supplier_responded_at',
    ];

    protected $casts = [
        'variance_quantity' => 'float',
        'variance_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'approved_by_supplier' => 'boolean',
        'supplier_responded_at' => 'datetime',
    ];

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class, 'goods_receipt_item_id');
    }

    public function compensatoryOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'compensatory_order_id');
    }
}
