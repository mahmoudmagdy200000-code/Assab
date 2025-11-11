<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Supplier;

class GoodsReceipt extends Model
{
    use SoftDeletes , HasUuids;

    protected $fillable = [
        'purchase_order_id',
        'receipt_number',
        'branch_id',
        'received_by_id',
        'received_by_type', // NEW
        'driver_name',
        'driver_contact',
        'vehicle_number',
        'arrival_time',
        'document_type',
        'invoice_number',
        'invoice_date',
        'supplier_id',
        'amount_before_tax',
        'vat_amount',
        'total_amount',
        'payment_terms',
        'due_date',
        'invoice_file',
        'total_items_received',
        'total_variance_items',
        'status',
        'notes',
    ];

    protected $casts = [
        'arrival_time' => 'datetime',
        'invoice_date' => 'date',
        'due_date' => 'date',
        'amount_before_tax' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receivedBy(): MorphTo
    {
        return $this->morphTo('received_by', 'received_by_type', 'received_by_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function variances(): HasMany
    {
        return $this->hasMany(GoodsReceiptVariance::class);
    }

    public function calculateVAT(): float
    {
        return $this->amount_before_tax * 0.15;
    }

    public function generateReceiptNumber(): string
    {
        return 'GR-' . date('Ymd') . '-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }
}
