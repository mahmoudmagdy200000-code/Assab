<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Supplier;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number',
        'branch_id',
        'branch_manager_id',
        'user_type', // NEW: branch_manager, cashier, etc.
        'order_type',
        'supplier_id',
        'purchasing_officer_id',
        'purchasing_officer_type', // NEW: for polymorphic
        'transfer_from_branch_id',
        'status',
        'priority',
        'total_amount',
        'total_items',
        'delivery_date',
        'latest_delivery_date',
        'special_instructions',
        'message',
        'notification_methods',
        'requested_date',
        'completed_at',
        'canceled_at',
        'rejection_reason',
        'rejected_by_id',
        'rejected_by_type', // NEW
        'rejected_at',
    ];

    protected $casts = [
        'notification_methods' => 'array',
        'delivery_date' => 'date',
        'latest_delivery_date' => 'date',
        'requested_date' => 'datetime',
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // Polymorphic relation for branch manager (can be BranchManager or other user types)
    public function branchManager(): MorphTo
    {
        return $this->morphTo('branch_manager', 'user_type', 'branch_manager_id');
    }

    // Alternative: Direct relation to BranchManager
    public function manager(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'branch_manager_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    // Polymorphic relation for purchasing officer
    public function purchasingOfficer(): MorphTo
    {
        return $this->morphTo('purchasing_officer', 'purchasing_officer_type', 'purchasing_officer_id');
    }

    public function transferFromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'transfer_from_branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(PurchaseTimeline::class);
    }

    public function modifications(): HasMany
    {
        return $this->hasMany(PurchaseModification::class);
    }

    public function trackingUpdates(): HasMany
    {
        return $this->hasMany(PurchaseTracking::class);
    }

    // Polymorphic relation for rejected by
    public function rejectedBy(): MorphTo
    {
        return $this->morphTo('rejected_by', 'rejected_by_type', 'rejected_by_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('order_type', $type);
    }

    public function scopeByBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    // Helper Methods
    public function canBeCanceled(): bool
    {
        return in_array($this->status, ['pending', 'draft', 'pending_confirmation']);
    }

    public function generateOrderNumber(): string
    {
        $prefix = match($this->order_type) {
            'direct_supplier' => 'DS',
            'purchasing_officer' => 'PO',
            'internal_transfer' => 'IT',
            'multiple_sources' => 'MS',
            default => 'PO'
        };

        return $prefix . '-' . date('Ymd') . '-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }
}
