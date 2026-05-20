<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\PurchaseOrder;

class SupplierFeedback extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'purchase_order_id',
        'branch_id',
        'rating',
        'quality_feedback',
        'delivery_satisfaction',
        'improvement_insights',
    ];

    protected $casts = [
        'rating' => 'decimal:2',
    ];

    /**
     * Get the supplier that owns this feedback
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the purchase order associated with this feedback
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Get the branch that provided this feedback
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
