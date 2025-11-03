<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Supplier;

class PurchaseReturn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_order_id',
        'goods_receipt_id',
        'return_number',
        'branch_id',
        'supplier_id',
        'created_by_id',
        'created_by_type', // NEW
        'return_date',
        'total_return_amount',
        'required_action',
        'status',
        'additional_notes',
        'approved_by_id',
        'approved_by_type', // NEW
        'approved_at',
        'rejected_by_id',
        'rejected_by_type', // NEW
        'rejected_at',
        'rejection_reason',
        'escalated_to_brand_owner',
        'escalation_reason',
        'resolved_by_id',
        'resolved_by_type', // NEW
        'resolved_at',
        'resolution_type',
        'refund_amount',
        'refund_method',
        'refund_note',
        'refund_file',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_return_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'escalated_to_brand_owner' => 'boolean',
        'resolved_at' => 'datetime',
        'refund_amount' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by', 'created_by_type', 'created_by_id');
    }

    public function approvedBy(): MorphTo
    {
        return $this->morphTo('approved_by', 'approved_by_type', 'approved_by_id');
    }

    public function rejectedBy(): MorphTo
    {
        return $this->morphTo('rejected_by', 'rejected_by_type', 'rejected_by_id');
    }

    public function resolvedBy(): MorphTo
    {
        return $this->morphTo('resolved_by', 'resolved_by_type', 'resolved_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(PurchaseReturnTimeline::class);
    }

    public function generateReturnNumber(): string
    {
        return 'RET-' . date('Ymd') . '-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }
}
