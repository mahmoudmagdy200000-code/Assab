<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\Priority;
use Modules\Purchase\Enums\ProcessingTime;
use Modules\Purchase\Enums\QualityLevel;

class PurchaseOrder extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'order_number',
        'order_type',
        'status',
        'branch_id',
        'requested_by',
        'sourceable_type',
        'sourceable_id',
        'from_branch_id',
        'to_branch_id',
        'supplier_id',
        'quality_level',
        'processing_time',
        'priority',
        'preferred_delivery_date',
        'latest_delivery_date',
        'expected_delivery_at',
        'actual_delivery_at',
        'notification_channels',
        'subtotal',
        'tax_amount',
        'tax_rate',
        'total_amount',
        'discount_amount',
        'total_items',
        'received_items',
        'message',
        'special_instructions',
        'rejection_reason',
        'cancellation_reason',
        'delay_reason',
        'transport_method',
        'estimated_transport_hours',
        'driver_name',
        'driver_contact',
        'vehicle_number',
        'temperature',
        'cooling_status',
        'ready_time',
        'parent_order_id',
        'submitted_at',
        'confirmed_at',
        'preparation_started_at',
        'dispatched_at',
        'received_at',
        'closed_at',
        'canceled_at',
        'rejected_at',
    ];

    protected $casts = [
        'order_type' => OrderType::class,
        'status' => OrderStatus::class,
        'quality_level' => QualityLevel::class,
        'processing_time' => ProcessingTime::class,
        'priority' => Priority::class,
        'notification_channels' => 'array',
        'preferred_delivery_date' => 'date',
        'latest_delivery_date' => 'date',
        'expected_delivery_at' => 'datetime',
        'actual_delivery_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_items' => 'integer',
        'received_items' => 'integer',
        'temperature' => 'decimal:2',
        'cooling_status' => 'boolean',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'preparation_started_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'closed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected $appends = [
        'status_label',
        'status_color',
        'order_type_label',
        'can_receive',
        'is_active',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = static::generateOrderNumber($order->order_type);
            }
        });
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Modules\Purchase\Database\Factories\PurchaseOrderFactory::new();
    }

    // Relationships
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'requested_by');
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function supplier(): BelongsTo
    {
        // Reference Supplier module's Supplier model
        return $this->belongsTo(\Modules\Supplier\Models\Supplier::class, 'supplier_id');
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'parent_order_id');
    }

    public function childOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'parent_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function latestGoodsReceipt(): HasOne
    {
        return $this->hasOne(GoodsReceipt::class)->latest();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function variances(): HasMany
    {
        return $this->hasMany(PurchaseVariance::class);
    }

    public function compensatoryOrders(): HasMany
    {
        return $this->hasMany(CompensatoryOrder::class, 'original_order_id');
    }

    public function returnOrders(): HasMany
    {
        return $this->hasMany(ReturnOrder::class);
    }

    public function timelines(): MorphMany
    {
        return $this->morphMany(OrderTimeline::class, 'timelineable')->orderBy('occurred_at', 'desc');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(OrderDocument::class, 'documentable');
    }

    // Accessors
    public function getStatusLabelAttribute(): string
    {
        return $this->status?->label() ?? 'Unknown';
    }

    public function getStatusColorAttribute(): string
    {
        return $this->status?->color() ?? '#6B7280';
    }

    public function getOrderTypeLabelAttribute(): string
    {
        return $this->order_type?->label() ?? 'Unknown';
    }

    public function getCanReceiveAttribute(): bool
    {
        return $this->status?->canReceive() ?? false;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status?->isActive() ?? false;
    }

    // Scopes
    public function scopeByStatus($query, OrderStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, OrderType $type)
    {
        return $query->where('order_type', $type);
    }

    public function scopeByBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeBySupplier($query, string $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeByRequestedBy($query, string $userId)
    {
        return $query->where('requested_by', $userId);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', OrderStatus::pendingStatuses());
    }

    public function scopeHistory($query)
    {
        return $query->whereIn('status', OrderStatus::historyStatuses());
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', OrderStatus::receivingStatuses());
    }

    public function scopeDraft($query)
    {
        return $query->where('status', OrderStatus::DRAFT);
    }

    public function scopeForReceiving($query)
    {
        return $query->whereIn('status', OrderStatus::receivingStatuses());
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('order_number', 'like', "%{$term}%")
                ->orWhereHas('items', function ($q) use ($term) {
                    $q->where('item_name', 'like', "%{$term}%");
                });
        });
    }

    public function scopeByDateRange($query, ?string $from, ?string $to)
    {
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }
        return $query;
    }

    public function scopeLast24Hours($query)
    {
        return $query->where('created_at', '>=', now()->subHours(24));
    }

    public function scopeLast7Days($query)
    {
        return $query->where('created_at', '>=', now()->subDays(7));
    }

    public function scopeLast30Days($query)
    {
        return $query->where('created_at', '>=', now()->subDays(30));
    }

    // Methods
    public static function generateOrderNumber(?OrderType $type = null): string
    {
        $prefix = match ($type) {
            OrderType::DIRECT_SUPPLIER => 'DS',
            OrderType::VIA_PURCHASING_OFFICER => 'PO',
            OrderType::INTERNAL_TRANSFER => 'IT',
            OrderType::MULTIPLE_SOURCES => 'MS',
            OrderType::TRANSFER_RECEIVED => 'TR',
            default => 'PO',
        };

        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));

        return "{$prefix}-{$date}-{$random}";
    }

    public function canTransitionTo(OrderStatus $newStatus): bool
    {
        return $this->status?->canTransitionTo($newStatus) ?? false;
    }

    public function transitionTo(OrderStatus $newStatus): bool
    {
        if (!$this->canTransitionTo($newStatus)) {
            return false;
        }

        $oldStatus = $this->status;

        // Prepare update data with status as string value
        $updateData = ['status' => $newStatus->value];

        // Set appropriate timestamp
        match ($newStatus) {
            OrderStatus::PENDING => $updateData['submitted_at'] = now(),
            OrderStatus::CONFIRMED => $updateData['confirmed_at'] = now(),
            OrderStatus::PREPARING => $updateData['preparation_started_at'] = now(),
            OrderStatus::ON_THE_WAY => $updateData['dispatched_at'] = now(),
            OrderStatus::DELIVERED => $updateData['actual_delivery_at'] = now(),
            OrderStatus::CLOSED => $updateData['closed_at'] = now(),
            OrderStatus::CANCELED => $updateData['canceled_at'] = now(),
            OrderStatus::CANCELLED_BY_BRANCH => $updateData['canceled_at'] = now(),
            OrderStatus::CANCELLED_BY_SUPPLIER => $updateData['canceled_at'] = now(),
            OrderStatus::REJECTED => $updateData['rejected_at'] = now(),
            // Deprecated statuses (for backward compatibility)
            OrderStatus::FULLY_APPROVED => $updateData['confirmed_at'] = now(),
            OrderStatus::PARTIAL_APPROVED => $updateData['confirmed_at'] = now(),
            OrderStatus::PARTIAL_CONFIRMED => $updateData['confirmed_at'] = now(),
            default => null,
        };

        // Update in single query
        $this->update($updateData);

        // Auto-check if order should transition to CONFIRMED after item status changes
        if ($this->status === OrderStatus::PENDING) {
            $this->checkAndTransitionToConfirmed();
        }

        return true;
    }

    /**
     * Check if all items are decided (confirmed or rejected) and transition order to CONFIRMED
     * Also checks if all items are cancelled and transitions order to CANCELLED accordingly
     * This is called automatically when item status changes
     */
    public function checkAndTransitionToConfirmed(): bool
    {
        // Only check if order is in PENDING status
        if ($this->status !== OrderStatus::PENDING) {
            return false;
        }

        // Reload items to get latest status
        $this->load('items');

        // Get all items
        $items = $this->items;

        if ($items->isEmpty()) {
            return false;
        }

        // Check if all items are cancelled FIRST (before checking if all are decided)
        // This is important because cancelled items are also "decided", but we want cancelled status
        $allCancelled = $items->every(function ($item) {
            return $item->status->isCancelled();
        });

        if ($allCancelled) {
            // Determine cancellation type based on item cancellation types
            $cancelledByBranch = $items->every(function ($item) {
                return $item->status === \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_BRANCH;
            });

            $cancelledBySupplier = $items->every(function ($item) {
                return $item->status === \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_SUPPLIER;
            });

            // Determine order cancellation status
            if ($cancelledByBranch) {
                return $this->transitionTo(OrderStatus::CANCELLED_BY_BRANCH);
            } elseif ($cancelledBySupplier) {
                return $this->transitionTo(OrderStatus::CANCELLED_BY_SUPPLIER);
            } else {
                // Mixed cancellation types or regular cancelled
                return $this->transitionTo(OrderStatus::CANCELED);
            }
        }

        // Check if all items are decided (confirmed, rejected, or cancelled)
        // This means no items are pending or waiting for approval
        // If all items are decided (mix of confirmed/rejected/cancelled), transition to CONFIRMED
        $allDecided = $items->every(function ($item) {
            return $item->status->isDecided();
        });

        if ($allDecided) {
            // All items are decided (confirmed/rejected/cancelled mix), transition order to CONFIRMED
            // This handles the case where some items are confirmed and others are cancelled
            return $this->transitionTo(OrderStatus::CONFIRMED);
        }

        return false;
    }

    public function calculateTotals(): void
    {
        $subtotal = $this->items()->sum('total_price');
        $taxAmount = $subtotal * ($this->tax_rate / 100);
        $totalAmount = $subtotal + $taxAmount - ($this->discount_amount ?? 0);
        $totalItems = $this->items()->count();

        $this->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'total_items' => $totalItems,
        ]);
    }

    public function submit(): bool
    {
        if ($this->status !== OrderStatus::DRAFT) {
            return false;
        }

        return $this->transitionTo(OrderStatus::PENDING);
    }

    public function cancel(?string $reason = null, bool $byBranch = false, bool $bySupplier = false): bool
    {
        if (!$this->status->isActive()) {
            return false;
        }

        $this->cancellation_reason = $reason;

        // Determine cancellation status based on who is canceling
        $cancelStatus = OrderStatus::CANCELED;
        if ($byBranch) {
            $cancelStatus = OrderStatus::CANCELLED_BY_BRANCH;
        } elseif ($bySupplier) {
            $cancelStatus = OrderStatus::CANCELLED_BY_SUPPLIER;
        }

        // Cancel all items that are not already cancelled
        // Load items to ensure we have the latest status
        $this->load('items');

        foreach ($this->items as $item) {
            // Only cancel items that are not already cancelled
            if (!$item->status->isCancelled()) {
                $item->status = OrderItemStatus::CANCELLED;
                $item->quantity_confirmed = 0;

                // Add cancellation reason to approval_data if provided
                if ($reason) {
                    $approvalData = $item->approval_data ?? [];
                    $approvalData['cancellation_reason'] = $reason;
                    $item->approval_data = $approvalData;
                }

                $item->save();
            }
        }

        return $this->transitionTo($cancelStatus);
    }

    public function reject(?string $reason = null): bool
    {
        $this->rejection_reason = $reason;
        return $this->transitionTo(OrderStatus::REJECTED);
    }

    public function markAsPreparing(): bool
    {
        return $this->transitionTo(OrderStatus::PREPARING);
    }

    public function markAsOnTheWay(): bool
    {
        return $this->transitionTo(OrderStatus::ON_THE_WAY);
    }

    public function markAsDelivered(): bool
    {
        return $this->transitionTo(OrderStatus::DELIVERED);
    }

    public function close(): bool
    {
        return $this->transitionTo(OrderStatus::CLOSED);
    }

    public function reportDelay(string $reason): bool
    {
        $this->delay_reason = $reason;
        return $this->transitionTo(OrderStatus::DELAYED);
    }
}
