<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Purchase\Enums\InspectionQuality;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\QualityLevel;

class PurchaseOrderItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'item_name',
        'item_logo',
        'item_sku',
        'category',
        'subcategory',
        'quantity_ordered',
        'quantity_confirmed',
        'quantity_received',
        'unit_of_measurement',
        'unit_price',
        'total_price',
        'discount',
        'quality_ordered',
        'quality_received',
        'available_in_source',
        'remaining_balance',
        'daily_consumption',
        'weekend_forecast',
        'next_supply_date',
        'expiry_date',
        'temperature',
        'cooling_status',
        'inspection_photo',
        'inspection_notes',
        'status',
        'original_quantity',
        'new_quantity',
        'modification_note',
        'is_alternative',
        'original_item_id',
        'is_gift',
        'gift_reason',
        'approval_type',
        'approval_data',
    ];

    protected $casts = [
        'status' => OrderItemStatus::class,
        'quality_ordered' => QualityLevel::class,
        'quality_received' => InspectionQuality::class,
        'quantity_ordered' => 'decimal:3',
        'quantity_confirmed' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'available_in_source' => 'decimal:3',
        'remaining_balance' => 'decimal:3',
        'daily_consumption' => 'decimal:3',
        'weekend_forecast' => 'decimal:3',
        'next_supply_date' => 'date',
        'expiry_date' => 'date',
        'temperature' => 'decimal:2',
        'cooling_status' => 'boolean',
        'original_quantity' => 'decimal:3',
        'new_quantity' => 'decimal:3',
        'is_alternative' => 'boolean',
        'is_gift' => 'boolean',
        'approval_data' => 'array',
    ];

    protected $appends = [
        'item_logo_url',
        'quantity_variance',
        'has_variance',
    ];

    // Relationships
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function originalItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'original_item_id');
    }

    public function alternatives(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'original_item_id');
    }

    public function goodsReceiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function returnOrderItems(): HasMany
    {
        return $this->hasMany(ReturnOrderItem::class);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Modules\Purchase\Database\Factories\PurchaseOrderItemFactory::new();
    }

    // Accessors
    public function getItemLogoUrlAttribute(): ?string
    {
        if (!$this->item_logo) {
            return null;
        }
        
        return str_starts_with($this->item_logo, 'http') 
            ? $this->item_logo 
            : asset('storage/' . $this->item_logo);
    }

    public function getQuantityVarianceAttribute(): float
    {
        if ($this->quantity_received === null) {
            return 0;
        }
        
        return $this->quantity_ordered - $this->quantity_received;
    }

    public function getHasVarianceAttribute(): bool
    {
        return $this->quantity_variance != 0 || 
               ($this->quality_ordered && $this->quality_received && 
                $this->quality_ordered->value !== $this->quality_received->value);
    }

    // Scopes
    public function scopeByOrder($query, string $orderId)
    {
        return $query->where('purchase_order_id', $orderId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->whereIn('status', [
            OrderItemStatus::CONFIRMED,
            OrderItemStatus::PARTIAL_CONFIRMATION,
            OrderItemStatus::CONFIRMED_NEED_TIME,
            OrderItemStatus::CONFIRMED_ALTERNATIVE_PRODUCT,
        ]);
    }

    public function scopeReceived($query)
    {
        return $query->where('status', 'received');
    }

    public function scopeWithVariance($query)
    {
        return $query->where('status', 'variance');
    }

    public function scopeGifts($query)
    {
        return $query->where('is_gift', true);
    }

    public function scopeAlternatives($query)
    {
        return $query->where('is_alternative', true);
    }

    public function scopeNeedsApproval($query)
    {
        return $query->whereIn('status', [
            OrderItemStatus::NEEDS_APPROVAL,
            OrderItemStatus::NEEDS_APPROVAL_SUPPLIER,
            OrderItemStatus::NEEDS_APPROVAL_BRANCH,
        ]);
    }

    // Methods
    public function calculateTotalPrice(): float
    {
        $quantity = $this->quantity_confirmed ?? $this->quantity_ordered;
        $this->total_price = ($quantity * $this->unit_price) - ($this->discount ?? 0);
        $this->save();
        
        return $this->total_price;
    }

    public function confirm(?float $quantity = null): void
    {
        $this->quantity_confirmed = $quantity ?? $this->quantity_ordered;
        $this->status = 'confirmed';
        $this->calculateTotalPrice();
        $this->save();
    }

    public function markAsReceived(float $quantity, ?string $quality = null): void
    {
        $this->quantity_received = $quantity;
        if ($quality) {
            $this->quality_received = InspectionQuality::from($quality);
        }
        $this->status = $this->hasVariance ? 'variance' : 'received';
        $this->save();
    }

    public function updateQuantity(float $newQuantity, ?string $note = null): void
    {
        $this->original_quantity = $this->quantity_ordered;
        $this->new_quantity = $newQuantity;
        $this->quantity_ordered = $newQuantity;
        $this->modification_note = $note;
        $this->calculateTotalPrice();
        $this->save();
    }

    public function calculateBalanceQuantity(): float
    {
        return $this->quantity_ordered - ($this->new_quantity ?? $this->quantity_ordered);
    }

    /**
     * Request partial approval (supplier can only confirm partial quantity)
     */
    public function requestPartialApproval(float $requestedQuantity, ?string $note = null): void
    {
        if ($this->status !== OrderItemStatus::PENDING) {
            throw new \InvalidArgumentException('Item must be in pending status to request partial approval');
        }

        if ($requestedQuantity >= $this->quantity_ordered) {
            throw new \InvalidArgumentException('Requested quantity must be less than ordered quantity for partial approval');
        }

        $this->status = OrderItemStatus::PARTIAL_CONFIRMATION;
        $this->approval_type = 'partial';
        $this->approval_data = [
            'original_quantity' => $this->quantity_ordered,
            'requested_quantity' => $requestedQuantity,
            'note' => $note,
        ];
        $this->quantity_confirmed = $requestedQuantity;
        $this->save();
    }

    /**
     * Request delivery time change
     */
    public function requestTimeChange(string $newDeliveryTime, string $reason, ?string $note = null): void
    {
        if ($this->status !== OrderItemStatus::PENDING) {
            throw new \InvalidArgumentException('Item must be in pending status to request time change');
        }

        $this->status = OrderItemStatus::NEEDS_APPROVAL;
        $this->approval_type = 'time_change';
        $this->approval_data = [
            'original_delivery_time' => $this->purchaseOrder->expected_delivery_at?->toDateTimeString(),
            'requested_delivery_time' => $newDeliveryTime,
            'reason' => $reason,
            'note' => $note,
        ];
        $this->save();
    }

    /**
     * Request alternative product
     */
    public function requestAlternative(string $alternativeItemId, string $alternativeItemName, ?float $price = null, string $reason, ?string $note = null): void
    {
        if ($this->status !== OrderItemStatus::PENDING) {
            throw new \InvalidArgumentException('Item must be in pending status to request alternative');
        }

        $this->status = OrderItemStatus::NEEDS_APPROVAL;
        $this->approval_type = 'alternative';
        $this->approval_data = [
            'original_item_id' => $this->item_id,
            'original_item_name' => $this->item_name,
            'alternative_item_id' => $alternativeItemId,
            'alternative_item_name' => $alternativeItemName,
            'alternative_price' => $price ?? $this->unit_price,
            'reason' => $reason,
            'note' => $note,
        ];
        $this->save();
    }

    /**
     * Approve approval request (branch manager approves supplier request or supplier approves branch request)
     */
    public function approveRequest(?array $additionalData = null): void
    {
        if (!in_array($this->status, [
            OrderItemStatus::NEEDS_APPROVAL,
            OrderItemStatus::NEEDS_APPROVAL_SUPPLIER,
            OrderItemStatus::NEEDS_APPROVAL_BRANCH,
            OrderItemStatus::PARTIAL_CONFIRMATION,
            OrderItemStatus::PARTIAL
        ])) {
            throw new \InvalidArgumentException('Item must be in needs_approval, needs_approval_supplier, needs_approval_branch, partial_confirmation, or partial status to approve request');
        }

        // Handle different approval types (they modify data only)
        if ($this->approval_type === 'partial') {
            $this->handlePartialApproval();
        } elseif (in_array($this->approval_type, ['time_change', 'need_time'])) {
            $this->handleTimeChangeApproval($additionalData);
        } elseif ($this->approval_type === 'alternative') {
            $this->handleAlternativeApproval($additionalData);
        }

        // Set status based on approval_type
        $this->status = match ($this->approval_type) {
            'partial' => OrderItemStatus::PARTIAL_CONFIRMATION,
            'time_change', 'need_time' => OrderItemStatus::CONFIRMED_NEED_TIME,
            'alternative' => OrderItemStatus::CONFIRMED_ALTERNATIVE_PRODUCT,
            default => OrderItemStatus::CONFIRMED,
        };

        // Clear approval data
        $this->approval_type = null;
        $this->approval_data = null;
        $this->calculateTotalPrice();
        $this->save();

        // Refresh purchase order and reload items to get latest status
        $this->purchaseOrder->refresh();
        $this->purchaseOrder->load('items');

        // Trigger order status check
        $this->purchaseOrder->checkAndTransitionToConfirmed();
    }

    /**
     * Reject approval request (branch manager rejects supplier request or supplier rejects branch request)
     * 
     * If item has approval_type (modification), sets status to CANCELED_MODIFICATION
     * Otherwise, sets status to REJECTED (first-time rejection)
     */
    public function rejectRequest(?string $reason = null): void
    {
        if (!in_array($this->status, [
            OrderItemStatus::NEEDS_APPROVAL,
            OrderItemStatus::NEEDS_APPROVAL_SUPPLIER,
            OrderItemStatus::NEEDS_APPROVAL_BRANCH,
            OrderItemStatus::PARTIAL_CONFIRMATION,
            OrderItemStatus::PARTIAL
        ])) {
            throw new \InvalidArgumentException('Item must be in needs_approval, needs_approval_supplier, needs_approval_branch, partial_confirmation, or partial status to reject request');
        }

        // Check if this is a modification cancellation (has approval_type)
        $isModificationCancellation = !empty($this->approval_type);
        
        // Set status: canceled_modification if it's a modification, otherwise rejected
        $this->status = $isModificationCancellation 
            ? OrderItemStatus::CANCELED_MODIFICATION 
            : OrderItemStatus::REJECTED;
        
        $this->quantity_confirmed = 0;
        
        // Store rejection reason in approval_data for history
        if ($reason) {
            $this->approval_data = array_merge($this->approval_data ?? [], ['rejection_reason' => $reason]);
        }
        
        // Keep approval_type when canceling modification (for display purposes)
        // Only clear it if it's not a modification cancellation
        if (!$isModificationCancellation) {
            $this->approval_type = null;
        }
        
        $this->save();

        // Refresh purchase order and reload items to get latest status
        $this->purchaseOrder->refresh();
        $this->purchaseOrder->load('items');

        // Trigger order status check
        $this->purchaseOrder->checkAndTransitionToConfirmed();
    }

    /**
     * Handle partial approval
     */
    private function handlePartialApproval(): void
    {
        // Quantity already set in requestPartialApproval
        // Just ensure it's correct
        if (isset($this->approval_data['requested_quantity'])) {
            $this->quantity_confirmed = $this->approval_data['requested_quantity'];
        }
    }

    /**
     * Handle time change approval
     */
    private function handleTimeChangeApproval(?array $additionalData): void
    {
        // Update order's expected_delivery_at if provided
        if ($additionalData && isset($additionalData['new_delivery_time'])) {
            $this->purchaseOrder->expected_delivery_at = $additionalData['new_delivery_time'];
            $this->purchaseOrder->save();
        } elseif (isset($this->approval_data['requested_delivery_time'])) {
            $this->purchaseOrder->expected_delivery_at = $this->approval_data['requested_delivery_time'];
            $this->purchaseOrder->save();
        }
    }

    /**
     * Cancel item by branch
     */
    public function cancelByBranch(?string $reason = null): void
    {
        if ($this->status->isCancelled()) {
            throw new \InvalidArgumentException('Item is already cancelled');
        }

        $this->status = OrderItemStatus::CANCELLED_BY_BRANCH;
        $this->quantity_confirmed = 0;
        
        if ($reason) {
            $this->approval_data = array_merge($this->approval_data ?? [], ['cancellation_reason' => $reason]);
        }
        
        $this->save();

        // Refresh purchase order and check status
        $this->purchaseOrder->refresh();
        $this->purchaseOrder->load('items');
        $this->purchaseOrder->checkAndTransitionToConfirmed();
    }

    /**
     * Cancel item by supplier
     */
    public function cancelBySupplier(?string $reason = null): void
    {
        if ($this->status->isCancelled()) {
            throw new \InvalidArgumentException('Item is already cancelled');
        }

        $this->status = OrderItemStatus::CANCELLED_BY_SUPPLIER;
        $this->quantity_confirmed = 0;
        
        if ($reason) {
            $this->approval_data = array_merge($this->approval_data ?? [], ['cancellation_reason' => $reason]);
        }
        
        $this->save();

        // Refresh purchase order and check status
        $this->purchaseOrder->refresh();
        $this->purchaseOrder->load('items');
        $this->purchaseOrder->checkAndTransitionToConfirmed();
    }

    /**
     * Cancel item (when order is cancelled - no specific by)
     */
    public function cancel(?string $reason = null): void
    {
        if ($this->status->isCancelled()) {
            throw new \InvalidArgumentException('Item is already cancelled');
        }

        $this->status = OrderItemStatus::CANCELLED;
        $this->quantity_confirmed = 0;
        
        if ($reason) {
            $this->approval_data = array_merge($this->approval_data ?? [], ['cancellation_reason' => $reason]);
        }
        
        $this->save();
    }

    /**
     * Handle alternative approval
     */
    private function handleAlternativeApproval(?array $additionalData): void
    {
        // Replace item with alternative
        if (isset($this->approval_data['alternative_item_id'])) {
            $this->item_id = $this->approval_data['alternative_item_id'];
            $this->item_name = $this->approval_data['alternative_item_name'] ?? $this->item_name;
            
            if (isset($this->approval_data['alternative_price'])) {
                $this->unit_price = $this->approval_data['alternative_price'];
            }
            
            $this->is_alternative = true;
            $this->quantity_confirmed = $this->quantity_ordered;
        }
    }
}

